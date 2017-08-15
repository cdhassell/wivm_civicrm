<?php 
/*
Plugin Name: WIVM CiviCRM Interface
Plugin URI: https://thehcpac.org
Description: Hooks to Wired Impact Volunteer Management to create contacts and activity records in CiviCRM.
Version: 0.1
Author: twowheeler
*/


/** 
 * This class uses a hook from Wired Impact Volunteer Management plugin to:
 *   1. find or create a matching contact in CiviCRM using the volunteer information, and
 *   2. add or update an activity record in CiviCRM to record the volunteer activity.
 *   
 * Assumes that an activity type of 'Volunteer' exists in CiviCRM.
 * Does not try to create such an activity type if it does not exist.
 * The relevant hook in WIVM is:
 *    do_action( 'wivm_after_opp_rsvp', $user_id, $opportunity_id );
 * If something goes wrong, it will silently fail and write to the log.
 *
 **/

class wivm_civicrm {
	public $cid;
	public $start;
	public $duration;
	public $address;
	public $subject;
	public $detail;
	public $activity_type;
	public $config;
	
	function __construct() {
		add_action( 'wivm_after_opp_rsvp', array( $this, 'wivm_civicrm_go'), 100, 2 );
 	}
 	
 	public function wivm_civicrm_go( $userID, $opp ) {
		// load CiviCRM		
		$path = dirname(plugin_dir_path( __FILE__ ));
		require_once( $path.'/civicrm/civicrm.settings.php' ) ;
		require_once( 'CRM/Core/Config.php');
		$this->config =& CRM_Core_Config::singleton();
		require_once( 'api/api.php' ); 	
		// get the data and create/update an activity
		if (
			$this->get_contactid($userID) &&
			$this->get_opportunity($opp) &&
			$this->get_activity_type($userID)) {
			$this->make_activity();
		}
	}
	
 	public function get_contactid($userID) {
		$user = get_user_by( 'ID', $userID );
		$this->write_log("Starting wivm_civicrm");
		if (!empty($user)) {
			$firstname = $user->first_name;
			$lastname = $user->last_name;
			$email = $user->email;
			$phone = $user->phone;
			// check if we have a saved contact id for the user		
			$this->cid = get_user_meta( $userID, "wivm_civicrm_contact_id", TRUE );
			if (empty($this->cid)) {  
				// nothing stored, so search for contact by name
				$contact_count = civicrm_api3( "Contact", "getcount", array (
					'first_name' => $firstname,
					'last_name' => $lastname, 
					'email' => $email,
				));
				if ($contact_count === 0) {  // if none found, create a contact 
					$params = array (
						'contact_type' => 'Individual', 
						'source' => 'Volunteer', 
						'first_name' => $firstname, 
						'last_name' => $lastname, 
						'email' => $email,
						'phone' => $phone,
					);
					$contact_create=civicrm_api3("Contact","create", $params);
					if ($contact_create['is_error'] == 0) {
						$this->cid = $contact_create['id'];
					}
					$this->write_log("WIVM CiviCRM contact created = {$this->cid}");
				} else {   // if one/more is found, get the contact_id 
					$params = array (
						'first_name' => $firstname,
						'last_name' => $lastname, 
						'email' => $email
					);
					$contact_get=civicrm_api3( "Contact", "get", $params );
					if ($contact_get['is_error'] == 0) {
						// how many did we find?
						if ($contact_count == 1) {  
							$this->cid = $contact_get['id'];
						} else {
							// if more than one, take the first one and hope for the best
							$this->cid = array_shift(array_keys($contact_get['values']));
						}
					}
				}
			}
		}
		// if something goes wrong, just write to log and bail
		if (empty($this->cid)) {
			$this->write_log("wivm_civicrm can't find a contact id for volunteer: ");
			$this->write_log($params);
			return FALSE;
		}
		// at this point we have a contact id - keep it in metadata
		add_user_meta( $userID, "wivm_civicrm_contact_id", $this->cid, TRUE );
		return TRUE;
	}	
		
	public function get_opportunity($opp) {	
		// get opportunity fields
		$opp_obj = new WI_Volunteer_Management_Opportunity( $opp );
		$opp_meta = $opp_obj->opp_meta;
		if ($opp_meta['one_time_opp'] && isset($opp_meta['start_date_time'])) {
			$this->start = date( 'd M Y H:i:s', $opp_meta['start_date_time'] );
			if (isset($opp_meta['end_date_time'])) {
				$this->duration = round(($opp_meta['end_date_time'] - $opp_meta['start_date_time'])/60); 
			}
		} else {
			$this->start = '';
			$this->duration = '';
		}
		$this->address = $opp_obj->format_address( FALSE );
		// this is the address of the volunteer job not the individual
		$this->detail = __("Volunteered for ").get_the_title($opp)." ".esc_url(get_permalink($opp));
		// Example: http://www.example.com/?post_type=volunteer_opp&#038;p=2370
		$this->subject = get_the_title($opp);
		return TRUE;
	}
		
	public function get_activity_type($userID) {
		// find the volunteer activity type for CiviCRM
		$this->activity_type = get_user_meta($userID, "wivm_civicrm_activity_type_id", TRUE);
		if (empty($this->activity_type)) {
			// we don't have one stored, so search for it
			$activity_types = civicrm_api3('Activity', 'getoptions', array(
				// do NOT use 'sequential' here
				'field' => "activity_type_id",
			));
			if ($activity_types['is_error'] == 0) {
				// array_search() returns the key of the matching value, or FALSE
				$found = array_search('Volunteer',$activity_types['values']);
				if ($found === FALSE || empty($found)) {
					$this->write_log("No volunteer activity type found in CiviCRM");
					$this->write_log($activity_types['values']);
					// we should create one here
					return FALSE;		// FIXME		
				} else {
					$this->activity_type = $found;
				}
			} else {
				$this->write_log("Error finding volunteer activity type in CiviCRM");
				return FALSE;
			}
		}
		add_user_meta($userID, "wivm_civicrm_activity_type_id", $this->activity_type, TRUE);
		return TRUE;
	}
		
	public function make_activity() {
		// add/update a CiviCRM activity record for this volunteer opportunity
		$activity_get = civicrm_api3('Activity', 'get', array(
			'sequential' => 1,
			'source_contact_id' => $this->cid,
			'subject' => $this->subject,
		));
		if ($activity_get['is_error'] == 0 && $activity_get['count'] > 0) {
			// activity already exists for this contact
			$old_activity = $activity_get['values'][0]['id'];
		}
		$params = array(
			'source_contact_id' => $this->cid,
			'target_contact_id' => $this->cid,
			'assignee_contact_id' => $this->cid,
			'activity_type_id' => $this->activity_type,  
			'subject' => $this->subject,
			'details' => $this->detail,
			'status_id' => 1,		// 1='Scheduled', 2='Completed', 3='Cancelled'
			'activity_date_time' => $this->start,
			'duration' => $this->duration,	// in minutes
			'location' => $this->address,		// from opportunity record
		);
		// if the activity already exists, add its ID so it is updated
		if (isset($old_activity) && $old_activity) $params['id'] = $old_activity;
		$activity_create = civicrm_api3('Activity', 'create', $params);
		if ($activity_create['is_error'] == 0) {
			$this->write_log("Activity created successfully");
		}
		return;
	}

	// A small helper function to dump variables to the log for debugging
   public function write_log( $log )  {
      if ( is_array( $log ) || is_object( $log ) ) {
         error_log( print_r( $log, true ) );
      } else {
         error_log( $log );
      }
   }

}

$wivm_civicrm_obj = new wivm_civicrm();

?>
