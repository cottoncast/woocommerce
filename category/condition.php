<?php
	if ( ! defined( 'ABSPATH' ) ) {
		exit; // Exit if accessed directly
	}

	class Cottoncast_Category_Condition
	{

	    const NAME = 'cc_condition';
	    const LABEL = 'Products must match';
	    const DESCRIPTION = 'Select a condition';
	    const SORT_ORDER = 150;

		public function __construct()
		{
			add_action('product_cat_add_form_fields', [ $this, 'add_new_field' ], self::SORT_ORDER, 1);
			add_action('product_cat_edit_form_fields', [ $this, 'add_edit_field' ], self::SORT_ORDER, 1);

			add_action('edited_product_cat', [ $this, 'save_field' ], self::SORT_ORDER, 1);
			add_action('create_product_cat', [ $this, 'save_field' ], self::SORT_ORDER, 1);
		}


		public function add_new_field()
		{
			$this->addCottoncastHeader();
			?>
			<div class="form-field">
				<label for="<?= self::NAME ?>>"><?php _e(self::LABEL, 'cc'); ?></label>

				<input type="radio" name="<?= self::NAME ?>" id="<?= self::NAME ?>_all" value="all">
                <label for="<?= self::NAME ?>_all"><?php _e('All conditions', 'cc'); ?></label>

                <input type="radio" name="<?= self::NAME ?>" id="<?= self::NAME ?>_any" value="any">
                <label for="<?= self::NAME ?>_any"><?php _e('Any condition', 'cc'); ?></label>

			</div>
			<?php
		}

		public function add_edit_field($term)
		{

			$term_id = $term->term_id;
			$value = get_term_meta($term_id, self::NAME, true);
			$this->addCottoncastHeader();
			?>
			<tr class="form-field">
				<th scope="row" valign="top"><label for="<?= self::NAME ?>"><?php _e(self::LABEL, 'cc'); ?></label></th>
				<td>
                    <input type="radio" name="<?= self::NAME ?>" id="<?= self::NAME ?>_all" value="all" <?= $value == 'all' ? 'checked="checked"' : '' ?>>
                    <label for="<?= self::NAME ?>_all"><?php _e('All conditions', 'cc'); ?></label>
                    <input type="radio" name="<?= self::NAME ?>" id="<?= self::NAME ?>_any" value="any" <?= $value == 'any' ? 'checked="checked"' : '' ?>>
                    <label for="<?= self::NAME ?>_any"><?php _e('Any condition', 'cc'); ?></label>
				</td>
			</tr>
			<?php
		}


		public function addCottoncastHeader()
        { ?>
            <tr class="form-field">
                <td colspan="2"><h2>Cottoncast Category Mapping</h2><p>Products that match these conditions are placed in this category.</p></td>
            </tr>
        <?php
        }


		public function save_field($term_id)
		{

			$value = filter_input(INPUT_POST, self::NAME);
			update_term_meta($term_id, self::NAME, $value);
		}

	}

	$cottoncast_category_condition = new Cottoncast_Category_Condition();



