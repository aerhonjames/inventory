<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Set is_protected = TRUE if you want created file is protected
 * And set the password
 * Base path: path of uploaded excel file
 * Limit rows: limit rows every request must be greater than 0
 * Fields values: It is use to map excel column and change into corresponding value and set as index in object
 * Fields names: Use to validate the first row of the generated inventory excel file if the excel file is valid to use in inventory.
 * The validation is strict it must be exact value.
 */

$config['inventory']['excel'] = (object)[
	'is_protected' => TRUE,
	'password' => '123456'
];

$config['inventory'] = [
	'base_path' => 'uploads/inventory', // ffor testing path
	'limit_rows' => 500, // value must be greater than 0
	'field_values' => [
		'A' => 'item_code',
		'B' => 'model_name',
		'C' => 'alt_model_name',
		'D' => 'year_model',
		'E' => 'color_name',
		'F' => 'color_code',
		'G' => 'dealer_id',
		'H' => 'dealer_code'
	],
	'field_names' => [ 
		'A' => 'Vehicle ID - SKU',
		'B' => 'Vehicle Model',
		'C' => 'Vehicle Desc.',
		'D' => 'Model Year',
		'E' => 'Ext. Color Desc',
		'F' => 'Ext. Color - Color Code',
		'G' => 'Dealer Code',
		'H' => 'Dealer Name'
	]
];
