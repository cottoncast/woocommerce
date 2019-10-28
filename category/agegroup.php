<?php
	if ( ! defined( 'ABSPATH' ) ) {
		exit; // Exit if accessed directly
	}

	class Cottoncast_Category_Agegroup
	{

	    const NAME = 'cc_agegroup';
	    const LABEL = 'Age Group';
	    const DESCRIPTION = 'Select a Age / Group';
	    const SORT_ORDER = 152;

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
                <select name="<?= self::NAME ?>" id="<?= self::NAME ?>">
                    <?php foreach ($this->getDropdownValues() as $value => $label): ?>
                    <option value="<?= $value ?>"><?= $label?></option>
                    <?php endforeach; ?>
                </select>
				<p class="description"><?php _e(self::DESCRIPTION, 'cc'); ?></p>
			</div>
			<?php
		}

		public function add_edit_field($term)
		{

			$term_id = $term->term_id;
			$selected_values = explode(',',get_term_meta($term_id, self::NAME, true));
			?>
			<tr class="form-field">
				<th scope="row" valign="top"><label for="<?= self::NAME ?>"><?php _e(self::LABEL, 'cc'); ?></label></th>
				<td>
                    <?php foreach ($this->getDropdownValues() as $value => $label): ?>
                        <input type="checkbox" name="<?= self::NAME ?>[<?= $value ?>]" id="<?= self::NAME ?>-<?= $value ?>" value="<?= $value ?>"<?= in_array($value, $selected_values) ? ' checked="checked"' : '' ?>/>
                        <label for="<?= self::NAME ?>-<?= $value ?>"><?php _e($label, 'cc'); ?></label>
                    <?php endforeach; ?>
					<p class="description"><?php _e(self::DESCRIPTION, 'cc'); ?></p>
				</td>
			</tr>
			<?php
		}


		public function save_field($term_id)
		{
			$values = $_POST[self::NAME];
			$values = implode(',',$values);
			update_term_meta($term_id, self::NAME, $values);
		}

		private function getDropdownValues()
        {
            $integration_config = json_decode(get_option('cottoncast_integration_config'));
            $values = [];

	        foreach ( $integration_config->response->attributes as $attribute ) {
	            if ($attribute->code == 'gender')
                {
                    foreach ($attribute->values as $value){
                        $values[$value->code] = $value->label;
                    }
                    return $values;
                }

            }

            return $values;
        }

	}

	$cottoncast_category_agegroup = new Cottoncast_Category_Agegroup();



