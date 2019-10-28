<?php
	if ( ! defined( 'ABSPATH' ) ) {
		exit; // Exit if accessed directly
	}

	class Cottoncast_Category_Tags
	{

	    const NAME = 'cc_tags';
	    const LABEL = 'Product Tags';
	    const DESCRIPTION = 'Enter one or more tags (separated by comma)';
	    const SORT_ORDER = 153;

		public function __construct()
		{
			add_action('product_cat_add_form_fields', [ $this, 'add_new_field' ], self::SORT_ORDER, 1);
			add_action('product_cat_edit_form_fields', [ $this, 'add_edit_field' ], self::SORT_ORDER, 1);

			add_action('edited_product_cat', [ $this, 'save_field' ], self::SORT_ORDER, 1);
			add_action('create_product_cat', [ $this, 'save_field' ], self::SORT_ORDER, 1);
		}


		public function add_new_field()
		{
			?>
			<div class="form-field">
				<label for="<?= self::NAME ?>>"><?php _e(self::LABEL, 'cc'); ?></label>
				<input type="text" name="<?= self::NAME ?>" id="<?= self::NAME ?>">
				<p class="description"><?php _e(self::DESCRIPTION, 'cc'); ?></p>
			</div>
			<?php
		}

		public function add_edit_field($term)
		{

			$term_id = $term->term_id;
			$value = get_term_meta($term_id, self::NAME, true);
			?>
			<tr class="form-field">
				<th scope="row" valign="top"><label for="<?= self::NAME ?>"><?php _e(self::LABEL, 'cc'); ?></label></th>
				<td>
					<input type="text" name="<?= self::NAME ?>" id="<?= self::NAME ?>" value="<?php echo esc_attr($value) ? esc_attr($value) : ''; ?>">
					<p class="description"><?php _e(self::DESCRIPTION, 'cc'); ?></p>
				</td>
			</tr>
			<?php
		}


		public function save_field($term_id)
		{

			$value = filter_input(INPUT_POST, self::NAME);
			update_term_meta($term_id, self::NAME, $value);
		}

	}

	$cottoncast_category_tags = new Cottoncast_Category_Tags();



