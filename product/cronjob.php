<?php
	if ( ! defined( 'ABSPATH' ) ) {
		exit; // Exit if accessed directly
	}

	function cc_add_cron_every_minute_schedule( $schedules ) {
		$schedules['every_minute'] = array(
			'interval' => 60,
			'display' => __('Every minute')
		);
		return $schedules;
	}
	add_filter( 'cron_schedules', 'cc_add_cron_every_minute_schedule' );


	add_action('init', 'cc_schedule_product_job');

	function cc_schedule_product_job() {

		if (! wp_next_scheduled ( 'cottoncast_product_queue_cronjob' )) {
			wp_schedule_event(time(), 'every_minute', 'cottoncast_product_queue_cronjob');
		}
	}

	add_action('cottoncast_product_queue_cronjob', 'cottoncast_process_product_queue');

	function cc_unschedule_product_job() {
		$timestamp = wp_next_scheduled ('cottoncast_product_queue_cronjob');
		wp_unschedule_event ($timestamp, 'cottoncast_product_queue_cronjob');
	}
	register_deactivation_hook (__FILE__, 'cc_unschedule_product_job');

	function cottoncast_process_product_queue()
	{
		$product = new Cottoncast_Products_Cronjob_Product;
		$product->run();
	}


	class Cottoncast_Products_Cronjob_Product
	{
		const QUEUE_STATUS_NEW = 1;
		const QUEUE_STATUS_PROCESSING = 2;
		const QUEUE_STATUS_DONE = 3;
		const QUEUE_STATUS_FAILED = 4;

		const MAX_JOBS_PER_BATCH = 2; // ~3000 products per day can be added/updated


		private $queue_table_name;

		private $product_id;
		private $parent;

		private $variation_id;
		private $variation;

		public function __construct()
		{
			global $wpdb;
			$this->queue_table_name = $wpdb->prefix . "cottoncast_products_queue";

		}


		public function run()
		{
			// cleanup failed jobs
			$this->cleanupFailedJobs();
			// process new jobs (max 2)
			$this->processNewJobs();

		}


		private function cleanupFailedJobs()
		{
			global $wpdb;
			$failedJobIds = $wpdb->get_results( "SELECT job_id FROM {$this->queue_table_name} WHERE status = ".self::QUEUE_STATUS_PROCESSING." AND timestamp < NOW() - INTERVAL 10 MINUTE");
			foreach ($failedJobIds as $job)
			{
				$wpdb->query($wpdb->prepare("UPDATE {$this->queue_table_name} SET status= %d, `timestamp`=NOW() WHERE job_id= %d", [self::QUEUE_STATUS_FAILED, $job->job_id]));
			}
		}


		private function processNewJobs()
		{
			global $wpdb;
			$newJobs = $wpdb->get_results( "SELECT job_id, payload FROM {$this->queue_table_name} WHERE status = ".self::QUEUE_STATUS_NEW. " LIMIT ".self::MAX_JOBS_PER_BATCH);

			foreach ($newJobs as $job)
			{
				$job->payload = json_decode($job->payload);
				$this->processNewJob($job);
			}
		}


		private function processNewJob($job)
		{
			$this->processProduct($job);
			$this->processProductAttributes($job);
			$this->processVariations($job);
			$this->processImages($job);
		}


		private function processProduct($job)
		{
			$this->product_id = wc_get_product_id_by_sku($job->payload->sku);

			if ($this->product_id) {
				$this->parent = $this->updateProduct($job);
			} else {
				$this->insertProduct($job);
				$this->parent = wc_get_product($this->product_id);
			}

			$this->parent->set_stock_status('instock');
			$this->parent->set_price($job->payload->price);
			$this->parent->save();

			wp_set_object_terms($this->product_id, $job->payload->tags, 'product_tag');

			//@todo Add category mapping
			$this->processCategories($job);
		}


		private function processVariations($job)
		{
			// process variations
			$child_skus = $this->getChildrenSkus();
			$job_variations_skus = [];

			foreach ($job->payload->variants as $jobVariant)
			{
				$job_variations_skus[] = $jobVariant->sku;
			}

			$insert = array_diff($job_variations_skus, $child_skus);
			$update = array_intersect($child_skus, $job_variations_skus);

			foreach ($job->payload->variants as $jobVariant)
			{
				if (in_array($jobVariant->sku, $insert))
				{
					$this->insertVariation($jobVariant, $job);
				}

				if (in_array($jobVariant->sku, $update))
				{
					$this->updateVariation($jobVariant, $job);
				}

				$this->variation->set_price($job->payload->price);
				$this->variation->set_regular_price($job->payload->price);

				foreach ($jobVariant->config->options as $option)
				{
					$attribute = $option->code;
					$value = $option->label;
					update_post_meta($this->variation_id, 'attribute_'.$attribute, $value);
				}

				$this->variation->save();
			}

			$delete = array_diff($child_skus, $job_variations_skus);
			$this->deleteVariations($delete);




		}


		private function insertProduct($job)
		{

			$this->product_id = wp_insert_post([
				'post_title' => $job->payload->name,
				'post_type' => 'product',
				'post_status' => $job->payload->status->code == 'P' ? 'publish' : 'draft',
				'post_content' => $job->payload->description,
				'post_excerpt' => $job->payload->short_description
			]);

			update_post_meta($this->product_id, '_sku', $job->payload->sku);
			update_post_meta( $this->product_id,'_visibility','visible');
			update_post_meta($this->product_id, '_is_cottoncast_product', 'yes');

			wp_set_object_terms($this->product_id, 'variable', 'product_type');
		}


		private function updateProduct($job)
		{
			$product = wc_get_product( $this->product_id );
			$product->set_name($job->payload->name);
			$product->set_status($job->payload->status->code == 'P' ? 'publish' : 'draft');
			$product->set_description($job->payload->description);
			$product->set_short_description($job->payload->short_description);

			return $product;
		}


		private function insertVariation($jobVariant, $job)
		{
			$this->variation_id = wp_insert_post([
				'post_title' => $job->payload->name . "({$jobVariant->sku})",
				'post_name'   => 'product-'.$this->product_id.'-variation-'.$jobVariant->sku,
				'post_type' => 'product_variation',
				'post_status' => $job->payload->status->code == 'P' ? 'publish' : 'draft',
				'post_parent' => $this->product_id,
				'guid'        => $this->parent->get_permalink()
			]);

			$this->variation = wc_get_product($this->variation_id);
			$this->variation->set_sku($jobVariant->sku);

		}

		private function updateVariation($jobVariant, $job)
		{
			$this->variation_id = wc_get_product_id_by_sku($jobVariant->sku);
			$this->variation = wc_get_product($this->variation_id);
		}

		private function deleteVariations($delete)
		{
			foreach ($delete as $deleteVariationSku)
			{
				$delete_variation_id = wc_get_product_id_by_sku($deleteVariationSku);
				$delete_product = wc_get_product($delete_variation_id);
				$delete_product->delete(true);
			}
			return true;
		}


		private function processImages($job)
		{
			// Download all unique (based on url) images
			$downloads = $this->downloadImages($job);
			$existing = $this->getExistingAttachments();

			$existing = $this->cleanupExistingAttachments($existing, $downloads);

			$new = $this->createNewAttachments($existing, $downloads);

			// now create a map for url_hash -> attachment_id
			$url_attachment_map = [];
			foreach ( $existing->url_hash as $idx => $hash ) {
				$url_attachment_map[$hash] = $existing->attachment_id[$idx];
			}

			foreach ( $new->url_hash as $idx => $hash ) {
				$url_attachment_map[$hash] = $new->attachment_id[$idx];
			}



			$this->setParentImages($job, $url_attachment_map);
			$this->setVariationImages($job, $url_attachment_map);

		}


		private function processCategories($job)
		{
			$category_ids = [];

			// loop through all categories and see if it is a match
			$args = array(
				'taxonomy'     => 'product_cat',
				'orderby'      => 'name',
				'show_count'   => 0,
				'pad_counts'   => 0,
				'hierarchical' => 0,
				'title_li'     => '',
				'hide_empty'   => 0
			);
			$all_categories = get_categories( $args );

			foreach ( $all_categories as $category ) {

				$conditions = [
					'term_id'           => $category->term_id,
					'condition'         => (string)get_term_meta($category->term_id, 'cc_condition', true),
					'product_category'  => (string)get_term_meta($category->term_id, 'cc_category', true),
					'agegroups'         => (array)explode(',',(string)get_term_meta($category->term_id, 'cc_agegroup', true)),
					'tags'              => array_map('trim',explode(',',strtolower(get_term_meta($category->term_id, 'cc_tags', true))))
				];

				$jobAttributes = [
					'product_category'  => $job->payload->category->code,
					'tags'              => array_map('strtolower',$job->payload->tags)
				];

				foreach ($job->payload->agegroups as $agegroup_job)
				{
					$jobAttributes['agegroups'][] = $agegroup_job->code;
				}

				$agegroups_intersect = count(array_intersect($conditions['agegroups'], $jobAttributes['agegroups']));
				$agegroups_condition_disabled = count($conditions['agegroups']) == 0 ? true : false;

				$tags_intersect = count(array_intersect($conditions['tags'], $jobAttributes['tags']));
				$tags_condition_disabled = empty($conditions['tags'][0]) ? true : false;

				if ($conditions['condition'] == 'all' &&
					$conditions['product_category'] == $jobAttributes['product_category'] &&
				    ($agegroups_condition_disabled || $agegroups_intersect ) &&
				    ($tags_condition_disabled || $tags_intersect)
				)
				{
					$category_ids[] = $category->term_id;
				}

				if ($conditions['condition'] == 'any' && (
				        $conditions['product_category'] == $jobAttributes['product_category'] ||
				        $agegroups_intersect  ||
				        $tags_intersect
					)
				)
				{
					$category_ids[] = $category->term_id;
				}


			}

			wp_set_object_terms($this->product_id, $category_ids, 'product_cat');
			return;
		}


		/**
		 * Downloads Images from an url. filename is the md5 hash of the full url. Extension from original filename.
		 */
		private function downloadImages($job)
		{
			$downloads = new stdClass;
			$download_urls = [];

			foreach ( $job->payload->variants as $variant ) {
				foreach ( $variant->images as $image ) {
					$download_urls[] = $image->url;
				}
			}

			$unique_download_urls = array_unique($download_urls);

			$downloads->urls = $unique_download_urls;
			$downloads->url_hash = array_map('sha1', $downloads->urls);


			foreach ( $unique_download_urls as $download_url ) {
				if (function_exists('curl_version'))
				{
					$ch = curl_init();
					curl_setopt ($ch, CURLOPT_URL, $download_url);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
					curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
					$binary = curl_exec($ch);
					curl_close($ch);
				} else if (ini_get('allow_url_fopen')) {
					$binary = file_get_contents($download_url);
				} else {
					throw new Exception("Your hosting provider does not allow downloads. Get a new one. This one sucks.");
				}


				$upload_dir = wp_upload_dir();
				$ext = pathinfo(parse_url($download_url, PHP_URL_PATH), PATHINFO_EXTENSION);

				$filename = sha1($download_url). '.'.$ext;

				// Check folder permission and define file location
				if( wp_mkdir_p( $upload_dir['path'] ) ) {
					$file = $upload_dir['path'] . '/' . $filename;
				} else {
					$file = $upload_dir['basedir'] . '/' . $filename;
				}
				// Create the image file on the server
				$downloads->filepath[] = $file;
				$downloads->ext[] = $ext;
				$downloads->image_hash[] = sha1($binary);
				file_put_contents( $file, $binary );

			}

			return $downloads;
		}


		private function getExistingAttachments()
		{
			$existing_attachments = new stdClass;
			$existing_attachments->attachment_id = $existing_attachments->url_hash = $existing_attachments->image_hash = [];

			$existing_attachments->attachment_id = $this->parent->get_gallery_image_ids();

			if ($this->parent->get_image_id() && !in_array($this->parent->get_image_id(), $existing_attachments->attachment_id ))
				$existing_attachments->attachment_id[] = $this->parent->get_image_id();


			foreach ( $existing_attachments->attachment_id as $idx => $id ) {
				$existing_attachments->url_hash[$idx] = get_post_meta($id, 'cc_url_hash', true);
				$existing_attachments->image_hash[$idx] = get_post_meta($id, 'cc_image_hash', true);
			}

			return $existing_attachments;
		}


		private function cleanupExistingAttachments($existing, $downloads)
		{
			//remove: Not in the download anymore ( also remove downloaded file)
			$to_be_removed_url_hash  = array_diff($existing->url_hash, $downloads->url_hash);
			$to_be_removed_image_hash  = array_diff($existing->image_hash, $downloads->image_hash);
			$to_be_removed_attachment_ids = [];

			// loop through each .. identify attachment ids ..
			foreach ($to_be_removed_image_hash as $hash)
			{
				$to_be_removed_attachment_ids[] = $existing->attachment_id[array_search($hash,$existing->image_hash)];
			}

			foreach ($to_be_removed_url_hash as $hash)
			{
				$to_be_removed_attachment_ids[] = $existing->attachment_id[array_search($hash,$existing->url_hash)];
			}

			$to_be_removed_attachment_ids = array_unique($to_be_removed_attachment_ids);

			$cleaned_existing = $existing;
			foreach ( $to_be_removed_attachment_ids as $toBeRemovedAttachmentId ) {
				$key = array_search($toBeRemovedAttachmentId, $existing->attachment_id);
				wp_delete_attachment($toBeRemovedAttachmentId);
				unset($cleaned_existing->attachment_id[$key], $cleaned_existing->url_hash[$key], $cleaned_existing->image_hash[$key] );
			}

			return $cleaned_existing;
		}


		private function createNewAttachments($existing, $downloads)
		{
			$newAttachmentHashes = array_diff($downloads->url_hash, $existing->url_hash);
			$new = new stdClass;

			foreach ( $newAttachmentHashes as $newAttachmentHash )
			{
				$key = array_search($newAttachmentHash,$downloads->url_hash);
				$attachment_id = $this->create_attachment($downloads->filepath[$key], $downloads->url_hash[$key],$downloads->image_hash[$key]);
				$new->url_hash[] = $downloads->url_hash[$key];
				$new->attachment_id[] = $attachment_id;
			}

			return $new;
		}


		private function create_attachment($filepath, $url_hash, $image_hash){

			$filename = basename($filepath);

			$wp_filetype = wp_check_filetype($filename, null );
			$attachment = array(
				'post_mime_type' => $wp_filetype['type'],
				'post_title' => sanitize_file_name( $filename ),
				'post_content' => '',
				'post_status' => 'inherit'
			);
			$attach_id = wp_insert_attachment( $attachment, $filepath, $this->product_id );
			require_once(ABSPATH . 'wp-admin/includes/image.php');
			$attach_data = wp_generate_attachment_metadata( $attach_id, $filepath );
			wp_update_attachment_metadata( $attach_id, $attach_data );

			update_post_meta($attach_id, 'cc_url_hash', $url_hash);
			update_post_meta($attach_id, 'cc_image_hash', $image_hash);

			return $attach_id;
		}


		private function setParentImages($job, $url_attachment_map)
		{
			$attachment_ids = [];

			foreach ( $job->payload->variants as $idx => $variant ) {
				foreach ( $variant->images as $idxImg => $image ) {
					if ($idx === 0 && $idxImg === 0)
					{
						$this->set_post_feature_image($this->product_id, $url_attachment_map[sha1($image->url)]);
					} else {
						$attachment_ids[] = $url_attachment_map[sha1($image->url)];
					}

				}
			}

			update_post_meta($this->product_id,'_product_image_gallery',implode(',', $attachment_ids));
		}


		private function setVariationImages($job, $url_attachment_map)
		{
			foreach ( $job->payload->variants as $idx => $variant ) {
				foreach ( $variant->images as $idxImg => $image ) {
					if ($idxImg === 0)
					{
						$variation_id = wc_get_product_id_by_sku($variant->sku);
						$this->set_post_feature_image($variation_id, $url_attachment_map[sha1($image->url)]);
					}
				}
			}
		}


		private function set_post_feature_image($postId, $attachmentId)
		{
			set_post_thumbnail( $postId, $attachmentId );
		}


		function processProductAttributes($job)
		{
			$available_attributes = $available_labels = $variations = $product_attributes_data = [];

			foreach ($job->payload->config->labels as $label)
			{
				$available_attributes[] = $label->code;
				$available_labels[] = $label->label;
			}

			foreach ($job->payload->variants as $idx => $variant)
			{
				foreach ($variant->config->options as $option)
				{
					$variations[$idx]['attributes'][$option->code] = $option->label;
				}
			}


			foreach ($available_attributes as $idx => $attribute)
			{
				$values = [];

				foreach ($variations as $variation)
				{
					$attribute_keys = array_keys($variation['attributes']);

					foreach ($attribute_keys as $key)
					{
						if ($key === $attribute)
						{
							$values[] = $variation['attributes'][$key];
						}
					}
				}
				$values = array_unique($values);

				$product_attributes_data[$attribute] = array(

					'name'         => $available_labels[$idx],
					'value'        => implode('|',$values),
					'is_visible'   => '1',
					'is_variation' => '1',
					'is_taxonomy'  => '0'

				);
			}

			update_post_meta($this->product_id, '_product_attributes', $product_attributes_data);
		}



		private function getChildrenSkus()
		{
			$child_skus = [];

			if (empty($this->parent)) throw new Exception("A parent product is required to get child products");

			$children_ids = $this->parent->get_children();

			foreach ($children_ids as $childrenId)
			{
				$child_product  = wc_get_product($childrenId);
				$child_skus[] = $child_product->get_sku();

			}
			return $child_skus;
		}


	}

