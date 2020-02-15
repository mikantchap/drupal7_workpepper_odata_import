<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
 
class WorkPepper {
	
	public $log = array();
	public $entityNames = array();
	public $entityLookup;
	private $username;
	private $password;
	private $auth_header;
	public $drupalCourses = array();
	public $drupalWorkshops = array();
	public $drupalLocations = array();
	public $wpModules = array();
	public $wpWorkshops = array();
	public $workshopUpdates = array();
	//public $wpLocations = array();
	public $wpVenues = array();
	public $onlyTheseCourseNids = array();
	
	function __construct($settings = array()) {
		
		if (isset($settings['onlyTheseCourseNids'])):
			$this->onlyTheseCourseNids = $settings['onlyTheseCourseNids'];
		endif;
		
		//can edit these at devel/variable
		$this->url = variable_get('tmc_workpepper_url', 'https://molapi.workpepper.com/odata');
		$this->username = variable_get('tmc_workpepper_username', 'workpepper');
		$this->password = variable_get('tmc_workpepper_password', 'mPnncm4G');
		
		
		/*
		$this->url = 'https://molapi.workpepper.com/odata';
		$this->username = 'XXXXXXXX';
		$this->password = 'XXXXXXXX';
		*/
		$this->auth_header = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
		//Authorization: Basic d29ya3BlcHBlcjptUG5uY200Rw==
	
		//Get a list of tables
		$this->getMetadata();
		
		//show a table if asked for 
		$this->getEntityData();
		
		if (isset($settings['makeVenueCodeOptions']) && $settings['makeVenueCodeOptions'] == true):
			//Make a list of venues for the drop down select
			//when editing 'Venue code' field in a location 	
			$this->getWPvenueCodes();
		endif;
		
		if (isset($settings['updateWorkshops']) && $settings['updateWorkshops'] == true):
		
			$this->getDrupalCourses();
			
			$this->getDrupalWorkshops();
			
			$this->getWPWorkshops();
			//this calls $this->lookupMDID()
				//this calls $this->lookupWorkshops()   (sets $this->wpModules)
				//and $this->firstWorkshopsAtLocation() (set $this->wpWorkshops)
			
			$this->makeWorkshopUpdates();
			$this->taxWorkshopUpdates();
			
			$minWorkshops = variable_get('tmc_workpepper_update_min_workshops', 3);
			
			if (count($this->workshopUpdates) >= $minWorkshops):
				$this->removeDrupalWorkshops();
				$this->updateCreateDrupalWorkshops();
			else:
				$this->log[] = "Error. No workshop Updates were generated.";
			endif;
		
		endif;
	}
	

	 /*
	  * Get a list of venues for the drop down select 
	  * when editing 'Venue code' field in a location 
	  */
	function getWPvenueCodes(){
		$url = $this->url;
		$url = $url . "/Venue";
		$ch = curl_init($url);
			
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($this->auth_header));
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
			
		$server_output = json_decode(curl_exec($ch));
	
		$venues = array();
		foreach ($server_output->value as $key => $venueObj):
			if ($venueObj->IsActive == true):
			$venues[$venueObj->Code] = $venueObj->Description;
			endif;
		endforeach;
		
		asort($venues);
		$options = array();
		$options["_none"] = "- None -";
		
		foreach ($venues as $code => $name):
		$options[$code] =  $name . " - " . $code;
		endforeach;

		$this->wpVenues = $options;
		
		$limit = 10;
		$errorMsg = 'Did not replace tmc_workpepper_location_venue_codes system variable.';
		$errorMsg .= ' There were fewer than ' . $limit . ' venues in WP';
		$errorMsg .= ' There were only ' . count($this->wpVenues);
		$success = 'System variable tmc_workpepper_location_venue_codes was updated';
			
		//$dummyOptions = array("_none" => "- None -", "ABER" => "Aberdeen - ABER");
		
		if (count($this->wpVenues) > $limit):
			variable_set('tmc_workpepper_location_venue_codes', $this->wpVenues);
			//variable_set('tmc_workpepper_location_venue_codes', $dummyOptions);
			watchdog('tmc_workpepper', $success);
		else:
			watchdog('tmc_workpepper', $errorMsg);
		endif;		
	}
	
	function updateCreateDrupalWorkshops(){
		
		foreach ($this->workshopUpdates as $wpWorkshopId => $workshopObj):
		
			$query = new EntityFieldQuery;
			$query->entityCondition('entity_type', 'node')
			->entityCondition('bundle', 'workshop')
			->fieldCondition('field_workshop_id', 'value', "$wpWorkshopId");
			$result = $query->execute();
			
			if (count($result['node']) == 0):
			
				//dsm("Didn't find an existing workshop node - its a create");				
				$node = new stdClass();
				$this->setNodeValues($node, $workshopObj, $workshopObj->refNids);
				$this->log[] = "Created node id = ".$node->nid.", Workshop Id ".$workshopObj->Id." <em>".$node->title . "</em>";
				
			
			elseif (count($result['node']) == 1):
				//dsm('Its an update for nid = ' . $node->nid. ' with workshop ID = ' . $workshopObj->Id);
				$workshop_nid = array_keys($result['node']);
				$node = node_load($workshop_nid[0]);
				$this->setNodeValues($node, $workshopObj, $workshopObj->refNids);
				$this->log[] = "Updated node id ".$node->nid.", Workshop Id ".$workshopObj->Id." <em>".$node->title . "</em>";
			
			elseif (count($result['node']) > 1):
				//dsm('duplicate workshop ids in nodes. Report, do nothing.');
				$this->log[] = "Error. These nodes (" . implode(" & ", array_keys($result['node'])) .") have the same workshop Id." . $workshopObj->Id;
				$this->log[] = "Error. Deleting Drupal workshops with these node ids: ".  implode(" & ", array_keys($result['node']));
				foreach (array_keys($result['node']) as $key => $nid):
				node_delete($nid);
				endforeach;
			endif;
	
		endforeach;		
	}
	
	
	/*
	 * Sets the node values and save
	*/
	function setNodeValues(&$node, $wpWorkshopObj, $refNids){
				
		global $user;
	
		//create only
		$node->type = "workshop";
		node_object_prepare($node); // Sets some defaults. Invokes hook_prepare() and hook_node_prepare().
		$node->language = LANGUAGE_NONE; // Or e.g. 'en' if locale is enabled
		$node->uid = $user->uid; //set to 1?
		$node->promote = 0; //(1 or 0): promoted to front page
		$node->comment = 0; // 0 = comments disabled, 1 = read only, 2 = read/write
		$node->sticky = 0;  // (1 or 0): sticky at top of lists or not
			
		//Static values, not from EBS
		$node->status = 1;
		$node->field_source[$node->language][0]['value'] = "automated";
		$node->path['pathauto'] = true;
		
		$node->title = $wpWorkshopObj->Name;
		$node->field_workshop_id[$node->language][0]['value'] = $wpWorkshopObj->Id;
		$node->field_workshop_date[$node->language][0]['value'] = substr($wpWorkshopObj->StartDate, 0, 10);
		$node->field_venue_code[$node->language][0]['value'] = $wpWorkshopObj->Venue->Code;
		
		$locationDescription = $this->wpModules[$wpWorkshopObj->ModuleId]->Location->Description;
		$node->field_wp_location[$node->language][0]['value'] = $locationDescription;
		
		$node->field_venue_address1[$node->language][0]['value'] = $wpWorkshopObj->Venue->Description;
		$node->field_venue_address2[$node->language][0]['value'] = $wpWorkshopObj->Venue->Address;
		$node->field_venue_city[$node->language][0]['value'] = $wpWorkshopObj->Venue->City;
		$node->field_venue_postcode[$node->language][0]['value'] = $wpWorkshopObj->Venue->Postcode;

		$address = array($wpWorkshopObj->Venue->Description, $wpWorkshopObj->Venue->Address, $wpWorkshopObj->Venue->City, $wpWorkshopObj->Venue->Postcode);
		$addressString = implode(",+", $address);
		
		$coords = $this->lookupLatLong($addressString);
		
			if ($coords !== false):
			
			$node->field_ws_geolocation['und'][0]['lat'] = $coords->lat;
			$node->field_ws_geolocation['und'][0]['lng'] = $coords->lng;
			
			endif;
		
		
		$node->field_wp_location_id[$node->language][0]['value'] = $wpWorkshopObj->modLocationId;
		
		$locationNids = $this->getDrupalLocationNid($wpWorkshopObj);

		//delete any existing location references
		unset($node->field_workshop_location['und']);
		if ($locationNids !== false):
			foreach ($locationNids as $key => $nid):
				$node->field_workshop_location['und'][$key]['target_id'] = $nid;
			endforeach;
		endif;
		
		//delete any existing course references
		unset($node->field_course['und']);
		foreach ($refNids as $key => $refNid):
			$refNidArray = array('target_id' => $refNid);
			$node->field_course['und'][] = $refNidArray;
		endforeach;
		
		//designate this workshop as non-manual content
		$node->field_manual_content['und'][0]['value'] = 0;
		
		//Prepare node for saving
		//Does creation date and node->uid
		$node = node_submit($node);
		node_save($node);
	}
	
	/*
	 * lookup the lat and longitude from Google
	 */
	function lookupLatLong($address){
		
		$coords = false;
		
		$url = "https://maps.googleapis.com/maps/api/geocode/json?address=";
		$address = str_replace(' ','+',$address);
		$keyString = "&key=AIzaSyCe0GkucQ3yH_fSXVhRAknjdsFbLT4tKxQ";		
		$geocode = file_get_contents($url .$address .$keyString);
		$output= json_decode($geocode);
		
		if (count($output->results) > 0):
		$coords = $output->results[0]->geometry->location;
		endif;
		
		return $coords;		
	}
	
	
	/*
	 * Removes Drupal workshop nodes whose workshop Id's don't
	 * exist in the incoming batch of wp workshops Id's
	 */
	function removeDrupalWorkshops(){
		
		$newWorkshopIDs = array();
		
		foreach ($this->wpWorkshops as $nid => $arrayOfWorkshops):
			foreach ($arrayOfWorkshops as $venueID => $workshopObj):
			$newWorkshopIDs[$workshopObj->Id] = $workshopObj->Id;
			endforeach;		
		endforeach;	
		
		$drupalWorkshopIDs = array();
		
		foreach ($this->drupalWorkshops as $nid => $nodeObj):
		$drupalWorkshopIDs[$nid] = (int) $nodeObj->field_workshop_id['und'][0]['safe_value'];
		
		endforeach;
		
		$workshopNodesToRemove = array_diff($drupalWorkshopIDs, $newWorkshopIDs);
		foreach ($workshopNodesToRemove as $nid => $drupalWorkshopIDs):
			//remove dead workshops
			node_delete($nid);
			$this->log[] = "Removed node " . $nid;
		endforeach;
	}
	
	/*
	 * get all the Drupal 'course' nodes with a course code set
	 */
	function getDrupalCourses(){
		
		$query = new EntityFieldQuery;
		$query->entityCondition('entity_type', 'node')
		->entityCondition('bundle', 'course')
		->propertyCondition('status', 1)
		//->fieldCondition('field_course_codes', 'value', "testvalue", "="); OK
		->fieldCondition('field_course_codes', 'value', "", "!=");
		//->fieldCondition('field_customfield4', 'value', $anemailaddress);

		$result = $query->execute();
		if (isset($result['node'])) {
			$course_nids = array_keys($result['node']);
			$this->drupalCourses = entity_load('node', $course_nids);
		}
	}
	/*
	 * get all existing Drupal 'workshop' nodes
	 */
	function getDrupalWorkshops(){
		
		$query = new EntityFieldQuery;
		$query->entityCondition('entity_type', 'node')
		->entityCondition('bundle', 'workshop')
		//->propertyCondition('status', 1);
		->fieldCondition('field_manual_content', 'value', 0, '=');
				
		$result = $query->execute();
		if (isset($result['node'])) {
			$workshop_nids = array_keys($result['node']);
			$this->drupalWorkshops = entity_load('node', $workshop_nids);
		}
	}
	
	/*
	 * get Drupal 'location' nid for a Venue code
	*/
	function getDrupalLocationNid($wpWorkshopObj){
		//dsm($wpWorkshopObj->Id . " " . $wpWorkshopObj->Name);
		//dsm(implode(" ", $wpWorkshopObj->taxParents));
		
		$venueCode = $wpWorkshopObj->Venue->Code;
		
		$query = db_select('node', 'n');
		$query->join('field_data_field_loc_venue_code', 'lvc', 'lvc.entity_id=n.nid');
		$query->join('field_data_field_taxonomy_website_section', 'tws', 'tws.entity_id=n.nid');
		$query->fields('n', array('nid', 'title', 'created'));
		$query->condition('status', 1);
		$query->condition('type', 'location');
		//->condition('uid', array(1,5,7),'IN')
		$query->condition('field_taxonomy_website_section_tid', $wpWorkshopObj->taxParents, 'IN');
		//$query->condition('field_taxonomy_website_section_tid', 5);
		$query->condition('field_loc_venue_code_value', $venueCode);
		
		$result = $query->execute();
		
		$find = $query->distinct()->execute();
		
		//dsm(str_replace("AND", PHP_EOL."AND", dpq($query, TRUE)));
		
		//convert results to array of nodes
		$nodeNids = array();
		foreach ($find as $item) :
			if (!in_array($item->nid, $nodeNids)):
			$nodeNids[] = $item->nid;
			endif;
		endforeach;
		
		if ($nodeNids):
			//dsm(implode(" ", $nodeNids));
			//dsm('');
			return $nodeNids;
		else:
			return false;
		endif;
		
		
		/*
		$query = new EntityFieldQuery;
		$query->entityCondition('entity_type', 'node')
		->entityCondition('bundle', 'location')
		->propertyCondition('status', 1)
		//->fieldCondition('field_loc_venue_code', 'value', "$venueCode"),
		->fieldCondition('field_loc_venue_code', 'value', "$venueCode");
		
		$result = $query->execute();
			
		$locations = array();
		if (isset($result['node'])) {
			$location_nids = array_keys($result['node']);
			$locations = entity_load('node', $location_nids);
			dsm($locations);
				foreach ($locations as $nid => $locObj):
					if (isset($locObj->field_taxonomy_website_section['und'])):
					
					endif;				
				endforeach;
			//there should be only 1 result because the venue
			//code should only be in 1 Drupal location
			
			//if (count($location_nids)> 1):
			//$this->log[] = "Error. Venue code $venueCode was found in > 1 node: ".implode(" ", $location_nids);
			//endif;
			
			return $location_nids;
		} else {
			
		}
		*/
	}

	
	/*
	 * Inverts $this->wpWorkshops so that we have an array of
	 * WP Workshop ID's vs Workshop objects (containing target ref nids) 
	 */
	function makeWorkshopUpdates(){
		
		$workshopUpdates = array();
		
		foreach ($this->wpWorkshops as $nid => $arrayOfWorkshops):
		
			foreach ($arrayOfWorkshops as $venueID => $workshopObj):
			
				//dsm($workshopObj);

				if (isset($workshopUpdates[$workshopObj->Id])):
					//just add the next refNid
					$workshopUpdates[$workshopObj->Id]->refNids[] = $nid; 
				else:
					//Add refNid to wp workshop Ob and add the 
					//object to the updates object
					$workshopObj->refNids[] = $nid; 
					$workshopUpdates[$workshopObj->Id] = $workshopObj;
				endif;
			
			endforeach;
		endforeach;
		
		$this->workshopUpdates = $workshopUpdates;
	}
	
	/*
	 * Process $this->workshopUpdates: make sure the workshop objects
	 * refer to courses within a single taxonomy branch and
	 * label them with that branch
	 */
	function taxWorkshopUpdates(){
		foreach ($this->workshopUpdates as $workshopId => $workshopArray):
			//$workshopArray->refNids are course nids
			$courses = entity_load('node', $workshopArray->refNids);
			$courseTaxes = array();
				foreach ($courses as $nid => $courseNode):
					if (isset($courseNode->field_subject_area['und'][0]['tid'])):
						$tid = $courseNode->field_subject_area['und'][0]['tid'];
						$parent_term = $this->_getTaxTreeTop($tid);
						//dsm($parent_term->tid);
						$courseTaxes[$parent_term->tid] = $parent_term->tid;
					endif;
				endforeach;
			$this->workshopUpdates[$workshopId]->taxParents = $courseTaxes;
		endforeach;
				
	}
	
	/*
	 * get the parent term for a tid
	 */
	function _getTaxTreeTop($tid){
	
		$parent_terms = taxonomy_get_parents_all($tid);
		
		//top parent term has no parents so find it out by checking if it has parents
		foreach($parent_terms as $parent) {
			$parent_parents = taxonomy_get_parents_all($parent->tid);
			if ($parent_parents != false) {
				//this is top parent term
				$top_parent_term = $parent;
			}
		}
		
		return $top_parent_term;		
	}

	/*
	 * Build an array of Drupal nids vs workshops
	 */
	function getWPWorkshops(){
		
		//if a diagnostic restriction has been supplied 
		if (count($this->onlyTheseCourseNids)):
			foreach ($this->drupalCourses as $nid => $drupalCourseObj):
				if (!in_array($nid, $this->onlyTheseCourseNids)):
					//remove the ones we don't want
					unset($this->drupalCourses[$nid]);
				endif;			
			endforeach;
		endif;
		
		
		

		foreach ($this->drupalCourses as $nid => $drupalCourseObj):
		
			$thisNodeWorkshops = array();
		
			//foreach code value in the course code field in the node
			if (isset($drupalCourseObj->field_course_codes['und'])):
				foreach ($drupalCourseObj->field_course_codes['und'] as $key => $arrayValues): 
					
				$moduleIDs = $this->lookupMDID($arrayValues['safe_value']);
					
					//each code brings back multiple module IDs
					//eg '3CHR' brings back 54 and 55
					foreach ($moduleIDs as $key => $moduleID):
					//Remove past workshops and find only the first (next) per location
					$thisNodeWorkshops = array_merge($thisNodeWorkshops, $this->lookupWorkshops($moduleID));
					endforeach;
					 
				endforeach;
			endif;
			
			$this->wpWorkshops[$nid] = $this->firstWorkshopsAtLocation($thisNodeWorkshops);
			
		endforeach;
	}
	
	
	/*
	 * get ModuleDefinitionId(s) from the workpepper ModuleDefinition
	* table based on CourseCode value (= ModuleDefinition.Title)
	*/
	function lookupMDID($title = null){
		
		$url = $this->url . "/" . 'ModuleDefinition';
		
		//https://molapi.workpepper.com/odata/ModuleDefinition?$filter=Title%20eq%203CHR&$expand=Workshops
		
		if (!is_null($title)):
			$url = $url . "?$" . "filter=Title%20eq%20%27" . $title . "%27";
		endif;
		
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($this->auth_header));
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		
		$server_output = json_decode(curl_exec($ch));
		
		$moduleIDs = array();
		if (isset($server_output->value)):
			foreach ($server_output->value as $key =>$obj):
				$moduleIDs[$obj->Id] = $obj->Id;
			endforeach;
		else:
			$this->log[] = "Error. Found nothing in the ModuleDefinition table for " . $title;
		endif;
		
		return $moduleIDs;
	}
	
	/*
	 * get (module + workshop) data from the workpepper Module table
	* table based on ModuleDefinitionID.
	*/
	function lookupWorkshops($moduleId){
		
		//OK https://molapi.workpepper.com/odata/Module?$filter=ModuleDefinitionId%20eq%20119
		$url = $this->url . "/" . 'Module';
		
		$todayDate = date('Y-m-d') . "T00:00:00Z";
		//$moduleFilter = "filter=HasWorkshops%20eq%20true%20and%20ModuleDefinitionId%20eq%20" . "$moduleId" . "%20and%20CourseStart%20gt%20" . $todayDate;
		$moduleFilter = "filter=HasWorkshops%20eq%20true%20and%20ModuleDefinitionId%20eq%20" . "$moduleId" . "%20and%20CourseStart%20gt%20" . $todayDate;
		
		//$workshopBrackets = "($". "expand=Venue;$". "filter=StartDate%20gt%20" . $todayDate .")";
		//Code%20Ne%20EVEN
		
		$workshopBrackets = "($". "expand=Venue)";
		
		//syntactically correct but just didn't work.....
		//$workshopBrackets = "($". "expand=Venue($" . "filter=Code%20eq%20'KAPL'))";
		
		$expandParams = "expand=Workshops" . $workshopBrackets . ",Location";
		
		//$filterVenueCodes = "%20and%20Venue/any(v :v/Code%20Ne%20'EVEN')";
		$url = $url . "?$" . $expandParams . "&$" . $moduleFilter; 
		
		//dsm($url);
		//($filter=StartDate%20gt%202017-01-01T00:00:00Z)
		//eg https://molapi.workpepper.com/odata/Module?$expand=Workshops&$filter=HasWorkshops%20eq%20true%20and%20ModuleDefinitionId%20eq%2054%20and%20CourseStart%20gt%202017-02-07T00:00:00Z
		
		$ch = curl_init($url);
	
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($this->auth_header));
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
	
		$server_output = json_decode(curl_exec($ch));
		
		$workshops = array();
		foreach ($server_output->value as $key =>$moduleObj):
		
			//Filter out corporate workshops
			if ($moduleObj->Location->IsCorporate == FALSE):	
		
				//dsm($moduleObj);
				//Refer to this later when setting workshop 
				//location (as opposed to venue)
				$this->wpModules[$moduleObj->Id] = $moduleObj;
	
				foreach ($moduleObj->Workshops as $key => $workshopObj):
					//Only adds the workshop once effectively
					$workshops[$workshopObj->Id] = $workshopObj;		
				endforeach;
			
			endif;//end if IsCorporate
			
		endforeach;
		//dsm($workshops);
		return $workshops;	
	}
	
	function firstWorkshopsAtLocation($workshops){
		$firstWorkshops = array();
		
		foreach ($workshops as $key => $workshopObj):
		//Add the date as a UNIX timestamp for easier comparison
		$workshopObj->UnixTimestamp = $this->convertToUnixTimestamp($workshopObj->StartDate);
		
		//dsm($workshopObj);
		/*
		if ($this->isXmas($workshopObj->StartDate)):
			unset($workshops[$key]);		
		endif;
		*/
		
		if ($this->hasWorkshopName($workshopObj) == false):
			unset($workshops[$key]);
		endif;

		//Add the parent Module's location Id
		$workshopObj->modLocationId = $this->wpModules[$workshopObj->ModuleId]->Location->Id;
		
		endforeach;
		
		foreach ($workshops as $key => $workshopObj):
			
			//if (isset($firstWorkshops[$workshopObj->Venue_Id])):
			if (isset($firstWorkshops[$workshopObj->modLocationId])):
			//do date comparison
				if ($workshopObj->UnixTimestamp < $firstWorkshops[$workshopObj->modLocationId]->UnixTimestamp ):
				
				//dsm($workshopObj->UnixTimestamp . " < " . $firstWorkshops[$workshopObj->Venue_Id]->UnixTimestamp);
				$firstWorkshops[$workshopObj->modLocationId] = $workshopObj;//->UnixTimestamp;
				endif;
			
			else:
			//just create the first date entry for this venue
			$firstWorkshops[$workshopObj->modLocationId] = $workshopObj;//->UnixTimestamp;
			endif;
			
		endforeach;
		
		
		
		return $firstWorkshops;
	}
	
	/*
	 * MySQL -> UNIX timestamp conversion
	 */
	function convertToUnixTimestamp($mysqlTimestamp){
		
		$mysqlTimestamp = substr($mysqlTimestamp, 0 , 19);
				
		list($date, $time) = explode('T', $mysqlTimestamp);
		list($year, $month, $day) = explode('-', $date);
		//list($hour, $minute, $second) = explode(':', $time);
		
		//return mktime($hour, $minute, $second, $month, $day, $year);
		return mktime(12, 0, 0, $month, $day, $year);
	}
	
	/*
	 * Checks whether the timestamp is Xmas which
	 * is used as a dummy date
	 */
	function isXmas($mysqlTimestamp){
	
		$mysqlTimestamp = substr($mysqlTimestamp, 0 , 19);
	
		list($date, $time) = explode('T', $mysqlTimestamp);
		list($year, $month, $day) = explode('-', $date);
		
		if ($month == 12 && $day == 25):
			return true;
		else:
			return false;
		endif;
	}
	
	/*
	 * checks whether it has a name
	 */
	function hasWorkshopName($workshopObj){
		
		if (isset($workshopObj->Name)):		
			return true;
		else:
			return false;
		endif;		
	}
	
	
	/**
	 * 	Get the metadata ie table names and
	 *  create a set of options for a form drop down select
	 */
	function getMetadata(){
	
		$url = $this->url;
		$ch = curl_init($url);
			
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($this->auth_header));
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
			
		$server_output = json_decode(curl_exec($ch));
			
		if (isset($server_output->value)):
		$this->metadata = $server_output->value;
			
		foreach ($this->metadata as $key => $obj):
		$this->entityNames[$obj->name] = $obj->name;
		endforeach;
	
		endif;
			
		curl_close($ch);
	}
	
	/*
	 * Shows the data for a table as DSM or as JSON
	*/
	function getEntityData(){
		
		if (isset($_POST['entity'])   &&   strlen($_POST['entity']) > 0   &&   in_array($_POST['entity'], $this->entityNames)):
	
		$url = $this->url . "/" . $_POST['entity'];
		$ch = curl_init($url);
			
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($this->auth_header));
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
			
		$server_output = json_decode(curl_exec($ch));
			
		//dsm($server_output->value);
		foreach ($server_output->value as $key => $obj):
		$this->displayOutputJson .=  PHP_EOL . json_encode($obj);
		endforeach;
	
		if ($_POST['displayOption'] == 1):
		dsm($this->displayOutputJson);
		else:
		dsm($server_output->value);
		endif;
			
		curl_close($ch);
			
		endif;
	}
	
} // end of class WorkPepper