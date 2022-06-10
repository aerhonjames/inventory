<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

use CI\Models\Import;
use CI\Models\ImportSheet;
use CI\Models\Dealer;
use CI\Models\Inventory;
use CI\Models\Variant;

class Inventory_importer{

    protected $ci;
	protected $import;
	protected $errors = [];	
	protected $warnings = [];	
	protected $missing = [
		'dealer'=>[], 
		'variant'=>[]
	];
	protected $total_rows = 0;

    public function __construct(){
        $this->ci =& get_instance();
        $this->ci->load->library('inventory_file');
        $this->import = new Import;
		$this->ci->activity->disable_model_logging(); // do not track logs via model
    }	

	function import($import=NULL){
		if($import instanceof Import){
			$this->import = $import;
			return $this;			
		}

		return $this->import;
	}		

	function tally($index=0){
		$import = $this->import;
		
		if(!$import->exists) $this->errors[] = 'No data to tally';
		if(in_array($import->status, ['done'])) $this->errors[] = 'Data was already imported.';

		if(!$this->has_errors()){
			$reader = $this->ci->inventory_file;

			$reader->last_read_index($index);
			$reader->file_path($import->file_path);
			$this->total_rows = $reader->get_excel_highest_row();
			$data = $reader->extract_data();		

			foreach($data as $_index=>$row){
				$skip = FALSE;
				$row = (object)$row;

				if($_index <= $import->last_read) $skip = TRUE;

				if(!$skip){
					$sheet = (new ImportSheet)
								->where('import_id', $import->id)
								->where('dealer_code', $row->dealer_code)
								->where('item_code', $row->item_code) 
								->where('color_code', $row->color_code)
								->first();

					if(!$sheet){
						$sheet = new ImportSheet;
						$sheet->import_id = $import->id;
						$sheet->dealer_code = $row->dealer_code;
						$sheet->item_code = $row->item_code; 
						$sheet->color_code = $row->color_code;
						$sheet->inventory = 1;
					}
					else{
						$sheet->inventory = $sheet->inventory + 1;
					}

					if($sheet->save()){
						$import->last_read = $_index;						
						$import->save();
						$this->import = $import; // re assign
					} 					

					// echo br().'okay';
				}
			}
		}
	}		

	function sync(int $page=1){
		$ci = $this->ci; 
		$ci->custom_pagination->current_page($page);
		
		$limit = $ci->config->item('limit_rows', 'inventory');

		$import = $this->import;	
		$sheet = $import->importSheet()
					->orderBy('id', 'asc')
					->paginate($limit);

		// print_array($sheet->toArray(), 1);
		
		if(!$import->exists OR !$sheet->count()) $this->errors[] = 'No data to import';
		if(in_array($import->status, ['done'])) $this->errors[] = 'Data was already imported.';

		if(!$this->has_errors()){
			foreach($sheet as $row){
				$variant_sku = sprintf('%s-%s', trim($row->item_code), trim($row->color_code)); // format using concatenation 
				
				$dealer = (new Dealer)
						->where('code', trim($row->dealer_code))
						->first();

				$variant = (new Variant)
						->where('sku', $variant_sku)
						->first();

				// capture missing dealer
				if(!$dealer){
					$this->warnings[] = 'Some dealers are missing.';
					$this->missing['dealer'][] = $row->dealer_id;
				} 

				// capture missing variant				
				if(!$variant){
					$this->warnings[] = 'Some variants are missing.';					
					$this->missing['variant'][] = $variant_sku;
				} 

				if($dealer AND $variant){
					// print_array($dealer->toArray());		
					$value = $row->inventory;
					$inventory = (new Inventory)
									->getByDealerVariant($dealer, $variant);

					if(!$inventory->exists){
						$inventory = $inventory->initialize($dealer, $variant, $value);
						$ci->activity->log(new Inventory, 'created', sprintf('created inventory (via import) for %s\'s %s', $dealer->name, $variant->label), $inventory, base_url('admin/dealers/inventories/form/'.$inventory->id));

					}
					else{
						if($value != $inventory->stock){							
							$new_value = $inventory->computeNewStockValue($value);								
							$inventory = $inventory->updateStock($new_value);
							$ci->activity->log(new Inventory, 'updated', sprintf('updated inventory (via import) for %s\'s %s', $dealer->name, $inventory->variant->label), $inventory, base_url('admin/dealers/inventories/form/'.$inventory->id));				
						}
					}			
				}
			}

			if($page >= $sheet->lastPage()){
				// mark as done
				$import->status = 'done';
				$import->save();
			}
				
			$this->import = $import; // re-assign import model
		}
	}	

	function errors(){
		if($this->errors) $this->errors = array_unique($this->errors);
		return $this->errors;
	}		

	function has_errors(){
		return ($this->errors) ? TRUE : FALSE;
	}	

	function missing(){
		if($this->missing){
			$this->missing['dealer'] = array_unique($this->missing['dealer']);
			$this->missing['variant'] = array_unique($this->missing['variant']);
		} 
		return $this->missing;
	}

	function total_rows(){
		return $this->total_rows;
	}

	function warnings(){
		if($this->warnings) $this->warnings = array_unique($this->warnings);
		return $this->warnings;
	}		

	function has_warnings(){
		return ($this->warnings) ? TRUE : FALSE;
	}	
}