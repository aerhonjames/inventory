<?php defined('BASEPATH') or exit('No direct script access allowed');

use CI\Models\Dealer;

class Inventory_excel{

	protected $ci;
	protected $folder;
	protected $filename;
	protected $errors = [];
	protected $base_path = 'uploads/inventory/';
	protected $session_key;
	protected $file_extension;
	protected $excel_password = '12345';
	protected $config;

	function __construct(){
		$this->ci =& get_instance();
		$this->ci->load->library('excel_creator');
		$this->ci->load->config('inventory');

		$this->session_key = sha1(base_url('dealer/inventories'));
		$this->file_extension = 'xlsx';
		$this->config = $this->ci->config->item('excel', 'inventory');
	}

	function folder($folder=NULL){
		if($folder) $this->folder = $folder;
		return $this;
	}

	function filename_affix($name=NULL){
		if($name) $this->filename = $name;
		return $this;
	}

	function upload($filename=NULL){
		$target_path = $this->generate_target_path();
		$new_filename = $this->generate_new_filename();

		$this->ci->session->set_userdata($this->session_key, $new_filename); // save the new filename in session for future reference

		$config['upload_path'] = $target_path;
        $config['allowed_types'] = 'xls|xlsx';
        $config['file_name'] = $new_filename;

        $this->check_folder_and_files();
        $this->delete_files();

        $this->ci->load->library('upload', $config);
        $this->ci->upload->do_upload($filename);

        if($errors = $this->ci->upload->display_errors('', '')){
        	$this->errors[] = $errors;
        }

        return (!$this->has_error()) ? TRUE : FALSE;
	}

	function read(){
		$target_file = $this->get_current_file_path();
		$worksheet = NULL;

		if(!file_exists($target_file)) $this->errors[] = sprintf('Read: No such file with this path %1$s', $target_file);

		$this->ci->excel_creator->file_path($target_file)->read();

		if(!$this->ci->excel_creator->has_error()){

			$spreadsheet = $this->ci->excel_creator->spreadsheet();
			$worksheet = $spreadsheet->getActiveSheet();

			$protection = $worksheet->getProtection();
			$is_valid_excel = $protection->verify($this->excel_password);

			$allowed_headers = ['SKU', 'Car', 'Inventory'];

			$excel_header = $spreadsheet->getActiveSheet()
			    ->rangeToArray(
			        'A1:C1',     // The worksheet range that we want to retrieve
			        NULL,        // Value that should be returned for empty cells
			        FALSE,        // Should formulas be calculated (the equivalent of getCalculatedValue() for each cell)
			        FALSE,        // Should values be formatted (the equivalent of getFormattedValue() for each cell)
			        TRUE         // Should the array be indexed by cell row and cell column
			    );

			if(!isset($excel_header[1]) OR array_diff($allowed_headers, $excel_header[1]) OR !$is_valid_excel) $this->errors[] = 'Read: You imported a invalid excel file.';
		}

		if(!$this->has_error() AND $worksheet){

			$highestRow = $worksheet->getHighestRow();
			$highestColumn = $worksheet->getHighestColumn(); 
			$highestColumn++;

			$data = [];

			for($row = 2; $row <= $highestRow; ++$row){

			    if($sku = $worksheet->getCell('A'.$row)->getValue()){
			    	$car = $worksheet->getCell('B'.$row)->getValue();
			    	$inventory = $worksheet->getCell('C'.$row)->getValue();
			    	
			    	if(isset($inventory)){
			    		$data[] = [
					    	'sku' => $sku,
			    			'car' => $car,
			    			'inventory' => $inventory
			    		]; 
			    	}
				    		    	
			    }
			}

			return $data;
		}

		return [];
	}

	function download_template($filename=NULL, $html_str=NULL){

		if(!$filename) $this->errors[] = 'Download template: Filename is required.';
		if(!$html_str) $this->errors[] = 'Download template: html string is required.';

		if(!$this->has_error()){
			$this->ci->excel_creator->filename($filename); // set filename of file to be download
			$this->ci->excel_creator
				->from_html($html_str)
				->worksheet()
				->is_protected($this->config->is_protected, $this->config->password);

			$options = [
				'style' => [
					'is_auto_size' => TRUE
				]
			];

			$this->ci->excel_creator->cell('A', NULL, $options);
			$this->ci->excel_creator->cell('B', NULL, $options);
			$this->ci->excel_creator->cell('C', NULL, [
				'protection' => [
					'is_protected' => FALSE,
					'start_row' => 2
				]
			]);

			$this->ci->excel_creator->download();
		}

		return (!$this->has_error() AND !$this->ci->excel_creator->has_error()) ? TRUE : FALSE;
	}

	function mark_as_done(){
		if($this->rename_upload_file('_done')) return TRUE;
		return FALSE;
	}

	function mark_as_invalid(){
		if($this->rename_upload_file('_invalid')) return TRUE;
		return FALSE;
	}

	function destroy_session(){
		$this->ci->session->unset_userdata($this->session_key);
	}

	function delete_files(){
		$target = $this->generate_target_path();
        
        if(is_dir($target)){
            $files = directory_map($target);
            
            if(is_array($files)){
                foreach($files as $file){
                	$target_file = sprintf('%s/%s', $target, $file);

                	if(str_contains($file, ['_done', '_invalid']) OR $this->is_file_expired($target_file)){
                    	@unlink($target_file);
                	}
                }
            }
        }
	}

	function errors(){
		return $this->errors;
	}

	function has_error(){
		if(count($this->errors)) return TRUE;
		return FALSE;
	}

	function is_file_expired($file_path=NULL){
		if(file_exists($file_path)){

			$today = date('yy-m-d', now());
			$file_date = date('yy-m-d', filemtime($file_path));

			if($file_date < $today) return TRUE;
		}

		return FALSE;
	}

	function generate_new_filename(){
		$filename = now();
		if($this->filename) $filename = sprintf('%1$s%2$s%3$s', now(), $this->filename, random_string('alnum', 4));
		return $filename;
	}

	function get_current_file_path(){
		$current_filename = $this->ci->session->userdata($this->session_key);

		if($current_filename){
			return sprintf('%1$s%2$s.%3$s', $this->generate_target_path(), $current_filename, $this->file_extension);
		}

		return NULL;
	}

	protected function generate_target_path(){
		if($this->folder) return sprintf('%1$s/%2$s', $this->base_path, $this->folder);
		return $this->base_path;
	}

	protected function check_folder_and_files(){
		if(!is_dir($this->generate_target_path())){ // validate if folder exists
            $path = explode('/', $this->generate_target_path());
            $generated = array();
            
            foreach($path as $segment){
                $generated[] = $segment;
                $target = join('/', $generated);
                if(!is_dir($target)) mkdir($target); // create directory
            }
        }
	}

	protected function rename_upload_file($filename_suffix='_done'){
		$current_filename = $this->ci->session->userdata($this->session_key);
		$target_path = $this->generate_target_path();

		if(is_dir($target_path)){
			$target_file = $this->get_current_file_path();
			if(file_exists($target_file)){
				$new_filename = str_replace($current_filename, $current_filename.$filename_suffix, $target_file);

				if(rename($target_file, $new_filename)) return TRUE;
			}
		}

		return FALSE;
	}
}