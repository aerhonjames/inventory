<?php defined('BASEPATH') or exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\Reader;
use CI\Models\Import;

class Inventory_file{

	protected $base_path;
	protected $ci;
	protected $errors = [];
	protected $last_read = 0;
	protected $target_path = NULL;
	protected $worksheet;
	protected $limit_rows = 50;

	function __construct(){
		$this->ci =& get_instance();
		$this->ci->load->config('inventory');
		$this->base_path = $this->ci->config->item('base_path', 'inventory');

		if($this->ci->config->item('limit_rows', 'inventory')){
			$this->limit_rows = $this->ci->config->item('limit_rows', 'inventory');
		}
	}

	function last_read_index($index=0){
		if($index) $this->last_read = $index;

		return $this->last_read;
	}

	function file_path($path=NULL){
		if($path){
			if(!$this->target_path) $this->target_path = $path;
		}

		return $this->target_path;
	}

	function upload($field_name=NULL){
		$new_filename = $this->generate_new_filename();

		$config['upload_path'] = $this->base_path;
        $config['allowed_types'] = 'xls|xlsx';
        $config['file_name'] = $new_filename;

        $this->check_folder_and_files();

        $this->ci->load->library('upload', $config);
        $this->ci->upload->do_upload($field_name);
        $errors = $this->ci->upload->display_errors('', '');

        if(!$errors){
        	$upload_info = (object)$this->ci->upload->data();
        	$file_path = sprintf('%1$s/%2$s', $this->base_path, $upload_info->file_name);

        	return $file_path;
        }
        else $this->errors[] = $errors;

        return NULL;
	}

	function errors(){
		return $this->errors;
	}

	function has_error(){
		if(count($this->errors)) return TRUE;
		return FALSE;
	}

	protected function get_file_extension($path=NULL){
		if($path){
			$path_part = pathinfo($path);
			return $path_part['extension'];
		}

		return NULL;
	}

	function extract_data(){
		$worksheet = $this->read_excel();

		if(!$this->has_error()){
			$read_data = [];
			$field_values = $this->ci->config->item('field_values', 'inventory');

			$highest_row = $this->get_excel_highest_row();
			$highest_column = $worksheet->getHighestColumn(); 
			$highest_column++;

			$last_read = $this->last_read_index();
			$start_index = $this->last_read_index() + 1;
			if($last_read == 0) $start_index = 2;

			$generated_minimum_rows = $this->generate_minimum_rows();

			for ($row = $start_index; $row <= $generated_minimum_rows; $row++) {
				$column_data = [];
			    for ($col = 'A'; $col != $highest_column; $col++) {
			            $value = $worksheet->getCell($col . $row)->getValue();
			            if(array_key_exists($col, $field_values)) $field_name = $field_values[$col];
			            else $field_name = $col;
			            
			            $column_data[$field_name] = $value; 
			    }

			    $read_data[$row] = $column_data;
			}

			return $read_data;
		}
	}

	protected function read_excel(){
		$file_path = $this->file_path();
		$extension = $this->get_file_extension($file_path);

		if(!file_exists($file_path)) $this->errors[] = 'Read Excel: Excel file not found.';

		if(!$this->has_error()){
			if(!$this->worksheet){
				if($extension === 'xls') $reader = new Reader\Xls;
				elseif($extension === 'xlsx') $reader = new Reader\Xlsx;

				$reader->setReadDataOnly(true);

				$spreadsheet = $reader->load($file_path);
				$this->worksheet = $spreadsheet->getActiveSheet();
			}

			return $this->worksheet;
		}
	}

	function get_excel_highest_row(){
		$worksheet = $this->read_excel();

		if(!$this->has_error()){
			$highest_row = $worksheet->getHighestRow();
			return $highest_row;
		}

		return NULL;
	}

	function limit_rows(){
		return $this->limit_rows;
	}

	function is_valid_excel_file($target_file_path=NULL){
		$field_names = $this->ci->config->item('field_names', 'inventory');
		if($target_file_path) $this->file_path($target_file_path);
		$target_file_path = $this->file_path();
		$is_valid = TRUE;

		if(file_exists($target_file_path)){
			$worksheet = $this->read_excel();
			$highest_column = $worksheet->getHighestColumn(); 
			$highest_column++;

			for ($col = 'A'; $col != $highest_column; $col++) {
		        $value = $worksheet->getCell($col . '1')->getValue();
		        $value = trim($value);

		        if($value !== $field_names[$col]) $is_valid = FALSE;
		    }
		    
		    return $is_valid;
		}

		return FALSE;
	}

	function delete_file($target_file_path=NULL){
        if($target_file_path) $this->file_path($target_file_path);
        $target_file_path = $this->file_path();
        
        if(file_exists($target_file_path)) return @unlink($target_file_path);

        return FALSE;
    }

    function delete_files(){
        if(is_dir($base_path)){
            $files = directory_map($base_path);
            
            if(is_array($files)){
                foreach($files as $file){
                    @unlink(sprintf('%s/%s', $base_path, $file));
                }
            }
        }
    }

	protected function generate_minimum_rows(){
		$last_read = $this->last_read_index();
		$minimum_rows = $this->limit_rows;

		if($last_read > 0){
			$last_read = $last_read + $minimum_rows;
			$highest_row = $this->get_excel_highest_row();

			if($last_read > $highest_row) $minimum_rows = $highest_row;
			else $minimum_rows = $last_read;

		}

		return $minimum_rows;
	}

	protected function check_folder_and_files(){
		if(!is_dir($this->base_path)){ // validate if folder exists
            $path = explode('/', $this->base_path);
            $generated = array();
            
            foreach($path as $segment){
                $generated[] = $segment;
                $target = join('/', $generated);
                if(!is_dir($target)) mkdir($target); // create directory
            }
        }
	}

	protected function generate_new_filename(){
		$filename = now();
		$filename = sprintf('%1$s%2$s', now(), random_string('alnum', 4));
		return $filename;
	}
}