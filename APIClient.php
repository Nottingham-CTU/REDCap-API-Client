<?php

namespace Nottingham\APIClient;

class APIClient extends \ExternalModules\AbstractExternalModule
{

	// Show the API Client link based on whether the user is able to edit the API connections.
	// If the user has no access, hide the link.
	function redcap_module_link_check_display( $project_id, $link )
	{
		if ( $this->canEditConnections() )
		{
			return $link;
		}
		return null;
	}



	// As the REDCap built-in module configuration only contains options for administrators, hide
	// this configuration from all non-administrators.
	function redcap_module_configure_button_display()
	{
		return $this->getUser()->isSuperUser() ? true : null;
	}



	// Function run when the module is enabled/updated.
	function redcap_module_system_enable( $version )
	{
		// Convert old connections data to v1.1.1+ format.
		foreach ( $this->getProjectsWithModuleEnabled() as $projectID )
		{
			$settings = $this->getProjectSettings( $projectID );
			foreach ( $settings as $settingKey => $settingValue )
			{
				if ( in_array( $settingKey, ['enabled', 'allow-normal-users-project'] ) )
				{
					continue;
				}
				$this->setSystemSetting( "p$projectID-$settingKey", $settingValue );
				$this->removeProjectSetting( $settingKey, $projectID );
			}
		}
	}



	// When the module is enabled on a project, move the settings to system settings to protect them
	// from export/import by unauthorised users, and check if there are any previously configured
	// scheduled connections to add to the global cron list.
	function redcap_module_project_enable( $version, $projectID )
	{
		$listConns = $this->getProjectSetting( 'conn-list', $projectID );
		if ( $listConns != '' )
		{
			$this->setSystemSetting( "p$projectID-conn-list", $listConns );
			$listConns = json_decode( $listConns, true );
			foreach ( $listConns as $connName )
			{
				$this->setSystemSetting( "p$projectID-conn-config-$connName",
				                         $this->getProjectSetting( "conn-config-$connName", $projectID ) );
				$this->setSystemSetting( "p$projectID-conn-data-$connName",
				                         $this->getProjectSetting( "conn-data-$connName", $projectID ) );
				$this->removeProjectSetting( "conn-config-$connName", $projectID );
				$this->removeProjectSetting( "conn-data-$connName", $projectID );
				$lastRun = $this->getProjectSetting( "conn-lastrun-$connID", $projectID );
				if ( $lastRun != '' )
				{
					$this->setSystemSetting( "p$projectID-conn-lastrun-$connID", $lastRun );
					$this->removeProjectSetting( "conn-lastrun-$connID", $projectID );
				}
			}
			$this->removeProjectSetting( 'conn-list', $projectID );
		}
		$this->updateCronListAllProjectConns();
	}



	// When the module is disabled on a project, move the settings from system settings to project
	// settings, and remove all the scheduled connections from the global cron list.
	function redcap_module_project_disable( $version, $projectID )
	{
		$this->updateCronListAllProjectConns( true );
		$listConns = $this->getSystemSetting( "p$projectID-conn-list" );
		if ( $listConns != '' )
		{
			$this->setProjectSetting( 'conn-list', $listConns, $projectID );
			$listConns = json_decode( $listConns, true );
			foreach ( $listConns as $connName )
			{
				$this->setProjectSetting( "conn-config-$connName",
				                          $this->getSystemSetting( "p$projectID-conn-config-$connName" ),
				                          $projectID );
				$this->setProjectSetting( "conn-data-$connName",
				                          $this->getSystemSetting( "p$projectID-conn-data-$connName" ),
				                          $projectID );
				$this->removeSystemSetting( "p$projectID-conn-config-$connName" );
				$this->removeSystemSetting( "p$projectID-conn-data-$connName" );
				$lastRun = $this->getSystemSetting( "p$projectID-conn-lastrun-$connID" );
				if ( $lastRun != '' )
				{
					$this->setProjectSetting( "conn-lastrun-$connID", $lastRun, $projectID );
					$this->removeSystemSetting( "p$projectID-conn-lastrun-$connID" );
				}
			}
			$this->removeSystemSetting( "p$projectID-conn-list" );
		}
	}



	// Apply any relevant connections when a record is saved.
	function redcap_save_record( $project_id, $record, $instrument, $event_id, $group_id = null,
	                             $survey_hash = null, $response_id = null, $repeat_instance = 1 )
	{
		// This should run after any other modules.
		if ( $this->delayModuleExecution() )
		{
			return;
		}
		// Get the unique event name for the event.
		$eventName = \REDCap::getEventNames( true, false, $event_id );
		$eventName = ( $eventName === false ) ? '' : $eventName;
		// Check if the submitted form is repeating.
		$repeatingForms = $this->getRepeatingForms( $event_id );
		$isRepeating = ( ( count( $repeatingForms ) == 1 && $repeatingForms[0] === null ) ||
		                 ( count( $repeatingForms ) > 0 &&
		                   in_array( $instrument, $repeatingForms ) ) );
		// Get the connections for the project.
		$listConnections = $this->getConnectionList();
		// Determine which connections are to be run.
		$listRunConnections = [];
		foreach ( $listConnections as $connID => $connConfig )
		{
			// Check that the connection is active and triggered on record save.
			if ( ! $connConfig['active'] || $connConfig['trigger'] != 'R' )
			{
				continue;
			}
			// Check that the event/form is the triggering event/form (if applicable).
			$connEventID = $connConfig['event'] == ''
			                    ? '' : \REDCap::getEventIdFromUniqueEvent( $connConfig['event'] );
			if ( ( $connConfig['event'] != '' && $connEventID != $event_id ) ||
			     ( $connConfig['form'] != '' && $connConfig['form'] != $instrument ) )
			{
				continue;
			}
			// Check the conditional logic (if applicable).
			if ( $connConfig['condition'] != '' &&
			     \REDCap::evaluateLogic( $connConfig['condition'], $project_id, $record, $event_id,
			                             ( $isRepeating ? $repeat_instance : 1 ),
			                             ( $isRepeating ? $instrument : null ) ) !== true )
			{
				continue;
			}
			$listRunConnections[ $connID ] = $connConfig;
		}
		// Run the connections.
		foreach ( $listRunConnections as $connID => $connConfig )
		{
			// Perform the appropriate logic for the connection type.
			$connData = $this->getConnectionData( $connID );
			$this->apiDebug( 'START CONNECTION: ' . $connConfig['label'] );
			$this->apiDebug( 'Triggered by form submission (' . $instrument . '), record ' .
			                 $record . ( $eventName == '' ? '' : ", $eventName" ) .
			                 ( $isRepeating ? ", instance $repeat_instance" : '' ) );
			if ( $connConfig['type'] == 'http' )
			{
				$this->performHTTP( $connData, $record, ( $isRepeating ? $repeat_instance : 0 ),
				                    $eventName );
			}
			elseif ( $connConfig['type'] == 'wsdl' )
			{
				$this->performWSDL( $connData, $record, ( $isRepeating ? $repeat_instance : 0 ),
				                    $eventName );
			}
			$this->apiDebug( 'END CONNECTION' );
		}
	}



	// Apply any relevant connections using cron job.
	function runCron( $infoCron )
	{
		$oldContext = $_GET['pid'];
		$execTime = time();
		$execDay = date( 'j', $execTime );
		$execMonth = date( 'n', $execTime );
		$execYear = date( 'Y', $execTime );
		$listCrons = $this->getSystemSetting( 'cronlist' );
		if ( $listCrons === null )
		{
			return;
		}
		$listCrons = json_decode( $listCrons, true );
		foreach ( $listCrons as $prConnID => $cronDetails )
		{
			// Get the project and connection ID for the cron, and set the project context.
			list( $projectID, $connID ) = explode( '.', $prConnID, 2 );
			$_GET['pid'] = $projectID;
			// Get the cron configuration, and test recent days for a match.
			$testDay = $execDay + 1;
			$testMonth = $execMonth;
			$testYear = $execYear;
			$isMatch = false;
			do
			{
				$testDay--;
				$testTime = mktime( $cronDetails['hr'], $cronDetails['min'], 0,
				                    $testMonth, $testDay, $testYear );
				$testDay = date( 'j', $testTime );
				$testMonth = date( 'n', $testTime );
				$testYear = date( 'Y', $testTime );
				$testDoW = date( 'w', $testTime );
				if ( $testTime <= $execTime &&
				     ( $cronDetails['day'] == '*' || $cronDetails['day'] == $testDay ) &&
				     ( $cronDetails['mon'] == '*' || $cronDetails['mon'] == $testMonth ) &&
				     ( $cronDetails['dow'] == '*' || $cronDetails['dow'] == $testDoW ) )
				{
					$isMatch = true;
				}
			}
			while ( ! $isMatch && $testTime > $execTime - ( 86400 * 7 ) );
			// If there is not a match, or if the most recent matching run time is equal or
			// prior to the last run time, proceed to the next cron item.
			if ( ! $isMatch ||
			     $testTime <= $this->getSystemSetting( "p$projectID-conn-lastrun-$connID" ) )
			{
				continue;
			}
			$this->setSystemSetting( "p$projectID-conn-lastrun-$connID", $execTime );
			// For each record (& each event if applicable)...
			$connConfig = $this->getConnectionConfig( $connID );
			$connData = $this->getConnectionData( $connID );
			$listEvents = [ null ];
			if ( isset( $connConfig['all_events'] ) )
			{
				$listEvents = \REDCap::getEventNames( true );
			}
			foreach ( array_keys( \REDCap::getData( [ 'project_id' => $projectID,
			                                          'return_format' => 'array',
			                                          'fields' => $this->getRecordIdField() ] ) )
			          as $record )
			{
				foreach ( $listEvents as $event )
				{
					// Check the conditional logic (if applicable).
					if ( $connConfig['condition'] != '' &&
					     \REDCap::evaluateLogic( $connConfig['condition'],
					                             $projectID, $record, $event ) !== true )
					{
						continue;
					}
					// Perform the appropriate logic for the connection type.
					if ( $connConfig['type'] == 'http' )
					{
						$this->performHTTP( $connData, $record, 0, $event ?? '' );
					}
					elseif ( $connConfig['type'] == 'wsdl' )
					{
						$this->performWSDL( $connData, $record, 0, $event ?? '' );
					}
				}
			}
		}
		$_GET['pid'] = $oldContext;
	}



	// If debugging active, write debugging data.
	function apiDebug( $data )
	{
		if ( isset( $_SESSION['module_apiclient_debug'] ) &&
		     $_SESSION['module_apiclient_debug']['ts'] > time() - 60 )
		{
			if ( ! isset( $_SESSION['module_apiclient_debug']['data'] ) )
			{
				$_SESSION['module_apiclient_debug']['data'] = '';
			}
			$_SESSION['module_apiclient_debug']['data'] .= $data . "\n";
		}
	}



	// Check if the API connections can be edited by the current user.
	function canEditConnections()
	{
		// Get user object.
		$user = $this->getUser();
		if ( ! is_object( $user ) )
		{
			return false;
		}
		// Administrators can always edit API connections.
		if ( $user->isSuperUser() )
		{
			return true;
		}
		// Non-administrators can be allowed/denied access to edit API connections at the system or
		// project level. Check both the system and project level settings.
		$canEditPr = $this->getProjectSetting( 'allow-normal-users-project' );
		$canEditSys = $this->getSystemSetting( 'allow-normal-users' );
		$canEdit = $canEditPr == 'A' || ( $canEditPr != 'D' && $canEditSys );
		$userRights = $user->getRights();
		// Don't allow access if denied. Also don't allow access by non-administrators without
		// user rights (in practice, such users probably cannot access the project).
		if ( ! $canEdit || $userRights === null )
		{
			return false;
		}
		// If access is allowed for non-administrators, grant access if the user has design rights
		// or the module specific rights if enabled.
		$specificRights = ( $this->getSystemSetting( 'config-require-user-permission' ) == 'true' );
		if ( ! $specificRights && $userRights[ 'design' ] == '1' )
		{
			return true;
		}
		$moduleName = preg_replace( '/_v[0-9.]+$/', '', $this->getModuleDirectoryName() );
		if ( $specificRights && is_array( $userRights['external_module_config'] ) &&
		     in_array( $moduleName, $userRights['external_module_config'] ) )
		{
			return true;
		}
		// Otherwise don't allow editing.
		return false;
	}



	// Add a new connection, with the specified configuration and data.
	function addConnection( $connConfig, $connData )
	{
		$projectID = $this->getProjectID();
		// Generate a new connection ID.
		$connID = '';
		$listIDs = $this->getSystemSetting( "p$projectID-conn-list" );
		if ( $listIDs === null )
		{
			$listIDs = [];
		}
		else
		{
			$listIDs = json_decode( $listIDs, true );
		}
		while ( $connID == '' || array_search( $connID, $listIDs ) !== false )
		{
			$connID = '';
			while ( strlen( $connID ) < 8 )
			{
				$chr = random_bytes(1);
				if ( preg_match( '/[a-z]/', $chr ) )
				{
					$connID .= $chr;
				}
			}
		}
		// Set the connection configuration and data.
		$this->setSystemSetting( "p$projectID-conn-config-$connID", json_encode( $connConfig ) );
		$this->setSystemSetting( "p$projectID-conn-data-$connID", json_encode( $connData ) );
		if ( $connConfig['active'] && $connConfig['trigger'] == 'C' )
		{
			$this->setSystemSetting( "p$projectID-conn-lastrun-$connID", time() );
		}
		else
		{
			$this->removeSystemSetting( "p$projectID-conn-lastrun-$connID" );
		}
		// Add the report to the list of reports.
		$listIDs = $this->getSystemSetting( "p$projectID-conn-list" );
		if ( $listIDs === null )
		{
			$listIDs = [];
		}
		else
		{
			$listIDs = json_decode( $listIDs, true );
		}
		$listIDs[] = $connID;
		$this->setSystemSetting( "p$projectID-conn-list", json_encode( $listIDs ) );
		$this->updateCronList( $this->getProjectID(), $connID, $connConfig );
	}



	// Delete the specified connection.
	function deleteConnection( $connID )
	{
		$projectID = $this->getProjectID();
		// Remove the connection configuration and data.
		$this->removeSystemSetting( "p$projectID-conn-config-$connID" );
		$this->removeSystemSetting( "p$projectID-conn-data-$connID" );
		$this->removeSystemSetting( "p$projectID-conn-lastrun-$connID" );
		$this->updateCronList( $this->getProjectID(), $connID, [ 'active' => false ] );
		// Remove the connection from the list of reports.
		$listIDs = $this->getSystemSetting( "p$projectID-conn-list" );
		if ( $listIDs === null )
		{
			return;
		}
		$listIDs = json_decode( $listIDs, true );
		if ( ( $k = array_search( $connID, $listIDs ) ) !== false )
		{
			unset( $listIDs[$k] );
		}
		$this->setSystemSetting( "p$projectID-conn-list", json_encode( $listIDs ) );
	}



	// Escapes text for inclusion in HTML.
	function escapeHTML( $text )
	{
		return htmlspecialchars( $text, ENT_QUOTES );
	}



	// Returns a list of events for the project.
	function getEventList()
	{
		$listTypes = explode( ',', $fieldTypes );
		$listEventNames = \REDCap::getEventNames( false, true );
		$listUniqueNames = \REDCap::getEventNames( true );
		$listEvents = [];
		foreach ( $listEventNames as $eventID => $eventName )
		{
			$uniqueName = $listUniqueNames[ $eventID ];
			$listEvents[ $uniqueName ] = $eventName;
		}
		return $listEvents;
	}



	// Get the configuration for the specified connection.
	// Optionally specify the configuration option name, otherwise all options are returned.
	function getConnectionConfig( $connID, $configName = null )
	{
		$projectID = $this->getProjectID();
		$config = $this->getSystemSetting( "p$projectID-conn-config-$connID" );
		if ( $config !== null )
		{
			$config = json_decode( $config, true );
			if ( $configName !== null )
			{
				if ( array_key_exists( $configName, $config ) )
				{
					$config = $config[ $configName ];
				}
				else
				{
					$config = null;
				}
			}
		}
		return $config;
	}



	// Get the connection definition data for the specified connection.
	function getConnectionData( $connID )
	{
		$projectID = $this->getProjectID();
		$data = $this->getSystemSetting( "p$projectID-conn-data-$connID" );
		if ( $data !== null )
		{
			$data = json_decode( $data, true );
		}
		return $data;
	}



	// Gets the list of connections, with the configuration data for each connection.
	function getConnectionList()
	{
		$projectID = $this->getProjectID();
		$listIDs = $this->getSystemSetting( "p$projectID-conn-list" );
		if ( $listIDs === null )
		{
			return [];
		}
		$listIDs = json_decode( $listIDs, true );
		$listConnections = [];
		foreach ( $listIDs as $id )
		{
			$infoConnection = [];
			$config = $this->getConnectionConfig( $id );
			if ( $config !== null )
			{
				$infoConnection = $config;
			}
			$listConnections[ $id ] = $infoConnection;
		}
		return $listConnections;
	}



	// Get the list of connection types.
	function getConnectionTypes()
	{
		return [ 'http' => 'HTTP / REST',
		         'wsdl' => 'SOAP (WSDL)' ];
	}



	// Get the value of a project field.
	function getProjectFieldValue( $recordID, $eventName, $fieldName, $instanceNum,
	                               $funcName = '', $funcParams = '' )
	{
		// Get the event ID from the event name (if applicable).
		$eventID = null;
		if ( $eventName != '' )
		{
			if ( defined( 'PROJECT_ID' ) )
			{
				$eventID = \REDCap::getEventIdFromUniqueEvent( $eventName );
			}
			else
			{
				$obProj = new \Project( $_GET['pid'] );
				$eventID = $obProj->getEventIdUsingUniqueEventName( $eventName );
			}
		}

		// Get the value for the (event and) field.
		$data = \REDCap::getData( [ 'project_id' => ( defined('PROJECT_ID')
		                                                    ? PROJECT_ID : $_GET['pid'] ),
		                            'return_format' => 'array', 'records' => $recordID,
		                            'fields' => $fieldName, 'events' => $eventID,
		                            'combine_checkbox_values' => true ] );
		$data = $data[$recordID];

		// Separate repeat instances/events data from the rest of the data.
		$repeatData = null;
		if ( isset( $data['repeat_instances'] ) )
		{
			$repeatData = $data['repeat_instances'];
			unset( $data['repeat_instances'] );
		}

		// Get the data corresponding to the chosen event.
		if ( $eventID == null )
		{
			if ( ! empty( $data ) )
			{
				$data = array_shift( $data );
			}
			if ( $repeatData !== null )
			{
				$repeatData = array_shift( $repeatData );
			}
		}
		else
		{
			if ( isset( $data[$eventID] ) )
			{
				$data = $data[$eventID];
			}
			else
			{
				$data = [];
			}
			if ( $repeatData !== null )
			{
				$repeatData = $repeatData[$eventID];
			}
		}

		// Extract the data for the specified field. If the data is in a repeating instance, find
		// it in the specified instance.
		if ( isset( $data[$fieldName] ) && $data[$fieldName] != '' )
		{
			$data = $data[$fieldName];
		}
		else
		{
			$data = '';
			if ( $repeatData !== null )
			{
				foreach ( $repeatData as $repeatInstances )
				{
					$selectedInstance = $instanceNum;
					if ( $selectedInstance < 1 )
					{
						$selectedInstance = count( $repeatInstances ) + $selectedInstance;
						if ( $selectedInstance < 1 )
						{
							$selectedInstance = 1;
						}
					}
					elseif ( $selectedInstance > count( $repeatInstances ) )
					{
						$selectedInstance = count( $repeatInstances );
					}
					if ( isset( $repeatInstances[$selectedInstance][$fieldName] ) &&
					     $repeatInstances[$selectedInstance][$fieldName] != '' )
					{
						$data = $repeatInstances[$selectedInstance][$fieldName];
						break;
					}
				}
			}
		}

		// If applicable, apply a function to the value.
		if ( $funcName == 'date' && $data != '' )
		{
			// Convert a date from YYYY-MM-DD to the specified format.
			$data = gmdate( $funcParams, gmmktime( intval( substr( $data, 11, 2 ) ),
			                                       intval( substr( $data, 14, 2 ) ),
			                                       intval( substr( $data, 17, 2 ) ),
			                                       intval( substr( $data, 5, 2 ) ),
			                                       intval( substr( $data, 8, 2 ) ),
			                                       intval( substr( $data, 0, 4 ) ) ) );
		}
		elseif ( $funcName == 'getline' && $data != '' &&
		         preg_match( '/^(0|(-?[1-9][0-9]*))$/', $funcParams ) )
		{
			// Get the specified line from multi-line text.
			$data = explode( "\n", str_replace( "\r\n", "\n", $data ) );
			$funcParams = intval( $funcParams );
			if ( $funcParams < 0 )
			{
				$funcParams = count( $data ) + $funcParams;
			}
			if ( $funcParams < 0 || $funcParams > count( $data ) - 1 )
			{
				$data = '';
			}
			$data = $data[ $funcParams ];
		}
		elseif ( $funcName == 'concatlines' && $data != '' )
		{
			$data = implode( $funcParams, explode( "\n", str_replace( "\r\n", "\n", $data ) ) );
		}
		// Return the value.
		return $data;
	}



	// Get the role name of the current user.
	function getUserRole()
	{
		$userRights = $this->getUser()->getRights();
		if ( $userRights === null )
		{
			return null;
		}
		if ( $userRights[ 'role_id' ] === null )
		{
			return null;
		}
		return $userRights[ 'role_name' ];
	}



	// Create a link for the current page with a modified query string variable.
	function makeQueryLink( $label, $variable, $value = '' )
	{
		if ( $_GET[ $variable ] == $value )
		{
			return '<em>' . $this->escapeHTML( $label ) . '</em>';
		}
		return '<a href="' . $this->escapeHTML( $this->makeQueryURL( $variable, $value ) ) .
		       '">' . $this->escapeHTML( $label ) . '</a>';
	}



	// Create a URL for the current page with a modified query string variable.
	function makeQueryURL( $variable, $value = '' )
	{
		$url = $_SERVER[ 'REQUEST_URI' ];
		$queryStart = strpos( $url, '?' );
		$urlVariable = rawurlencode( $variable );
		if ( $queryStart === false )
		{
			$urlBase = $url;
			$urlQuery = '';
		}
		else
		{
			$urlBase = substr( $url, 0, $queryStart );
			$urlQuery = substr( $url, $queryStart + 1 );
			$urlQuery = explode( '&', $urlQuery );
			foreach ( $urlQuery as $index => $item )
			{
				if ( substr( $item, 0, strlen( $urlVariable ) + 1 ) == "$urlVariable=" )
				{
					unset( $urlQuery[ $index ] );
				}
			}
			$urlQuery = implode( '&', $urlQuery );
		}
		$url = $urlBase . ( $urlQuery == '' ? '' : ( '?' . $urlQuery ) );
		if ( $value != '' )
		{
			$url .= ( $urlQuery == '' ? '?' : '&' );
			$url .= $urlVariable . '=' . rawurlencode( $value );
		}
		return $url;
	}



	// Output a drop-down list of events for the project.
	function outputEventDropdown( $dropDownName, $value, $includeCurrentEvent = false )
	{
		echo '<select name="', $this->escapeHTML( $dropDownName ), '">';
		echo '<option value=""', ( $value == '' ? ' selected' : '' ), '></option>';
		if ( $includeCurrentEvent )
		{
			echo '<option value="%"' , ( $value == '%' ? ' selected' : '' ),
			     '>Current Event</option>';
		}
		foreach ( $this->getEventList() as $optValue => $optLabel )
		{
			echo '<option value="', $this->escapeHTML( $optValue ), '"',
			     ( $value == $optValue ? ' selected' : '' ), '>',
			     $this->escapeHTML( $optLabel ), '</option>';
		}
		echo '</select>';
	}



	// Output a drop-down list of fields for the project.
	function outputFieldDropdown( $dropDownName, $value )
	{
		$listForms = \REDCap::getInstrumentNames();
		$formName = '';
		echo '<select name="', $this->escapeHTML( $dropDownName ), '">';
		echo '<option value=""', ( $value == '' ? ' selected' : '' ), '></option>';
		foreach ( \REDCap::getFieldNames() as $optValue )
		{
			if ( \REDCap::getFieldType( $optValue ) == 'descriptive' )
			{
				continue;
			}
			$optLabel = $this->getFieldLabel( $optValue );
			$optLabel = str_replace( ["\r\n", "\n"], ' ', $optLabel );
			$optLabel = trim( preg_replace( '/\\<[^<>]+\\>/', ' ', $optLabel ) );
			if ( strlen( $optLabel ) > 35 )
			{
				$optLabel = substr( $optLabel, 0, 25 ) . ' ... ' . substr( $optLabel, -8 );
			}
			$optLabel = $optValue . ( $optLabel == '' ? '' : ( ' - ' . $optLabel ) );
			$fieldForm = $this->getProject()->getFormForField( $optValue );
			if ( $formName != $fieldForm )
			{
				echo $formName == '' ? '' : '</optgroup>';
				echo '<optgroup label="', $this->escapeHTML( $listForms[ $fieldForm ] ), '">';
			}
			echo '<option value="', $this->escapeHTML( $optValue ), '"',
			     ( $value == $optValue ? ' selected' : '' ), '>',
			     $this->escapeHTML( $optLabel ), '</option>';
			$formName = $fieldForm;
		}
		echo '</optgroup>';
		echo '</select>';
	}



	// Output a drop-down list of forms/instruments for the project.
	function outputFormDropdown( $dropDownName, $value )
	{
		echo '<select name="', $this->escapeHTML( $dropDownName ), '">';
		echo '<option value=""', ( $value == '' ? ' selected' : '' ), '></option>';
		foreach ( \REDCap::getInstrumentNames() as $optValue => $optLabel )
		{
			echo '<option value="', $this->escapeHTML( $optValue ), '"',
			     ( $value == $optValue ? ' selected' : '' ), '>',
			     $this->escapeHTML( $optLabel ), '</option>';
		}
		echo '</select>';
	}



	// Perform a HTTP REST request.
	function performHTTP( $connData, $recordID, $defaultInstance, $defaultEvent )
	{
		// Get the URL, request method, headers and body.
		$url = $connData['url'];
		$method = $connData['method'];
		$headers = $connData['headers'];
		$body = $connData['body'];
		$this->apiDebug( 'HTTP Request (method: ' . strtoupper( $method ) . ')' );
		$this->apiDebug( 'Parameters (pre-placeholder replacement):' );
		$this->apiDebug( '  HTTP URL: ' . $url );
		$this->apiDebug( '  Headers: ' . str_replace( "\n", "\n           ", $headers ) );
		$this->apiDebug( '  Body: ' . str_replace( "\n", "\n        ", $body ) );
		// Get the placeholder name/value pairs.
		$listPlaceholders = [];
		if ( ! isset( $connData['ph_name'] ) || ! is_array( $connData['ph_name'] ) )
		{
			$connData['ph_name'] = [];
		}
		for ( $i = 0; $i < count( $connData['ph_name'] ); $i++ )
		{
			if ( $connData['ph_name'][$i] == '' )
			{
				continue;
			}
			$useInstance =
				( $connData['ph_inst'][$i] === '' ? $defaultInstance : $connData['ph_inst'][$i] );
			$useEvent = ( $connData['ph_event'][$i] ?? '' );
			$useEvent = ( $useEvent == '%' ) ? $defaultEvent : $useEvent;
			$placeholderValue =
				$this->getProjectFieldValue( $recordID, $useEvent,
				                             $connData['ph_field'][$i], $useInstance,
				                             $connData['ph_func'][$i],
				                             $connData['ph_func_args'][$i] );
			// Apply format if applicable.
			if ( $connData['ph_format'][$i] == 'base64' )
			{
				$placeholderValue = base64_encode( $placeholderValue );
			}
			elseif ( $connData['ph_format'][$i] == 'url' )
			{
				$placeholderValue = rawurlencode( $placeholderValue );
			}
			// Add the placeholder name and formatted value to the list.
			$listPlaceholders[ $connData['ph_name'][$i] ] = $placeholderValue;
		}
		// Search/replace the placeholder names with the values.
		$this->apiDebug( 'Placeholders:' );
		foreach ( $listPlaceholders as $placeholderName => $placeholderValue )
		{
			$placeholderValue = array_reduce( [ $placeholderValue ],
			                                  function( $c, $i ) { return $c . $i; }, '' );
			$this->apiDebug( '  ' . $placeholderName . ' => ' . $placeholderValue );
			$url = str_replace( $placeholderName, $placeholderValue, $url );
			$headers = str_replace( $placeholderName, $placeholderValue, $headers );
			$body = str_replace( $placeholderName, $placeholderValue, $body );
		}
		$this->apiDebug( 'Parameters (post-placeholder replacement):' );
		$this->apiDebug( '  HTTP URL: ' . $url );
		$this->apiDebug( '  Headers: ' . str_replace( "\n", "\n           ", $headers ) );
		$this->apiDebug( '  Body: ' . str_replace( "\n", "\n        ", $body ) );
		// Check that the URL is valid.
		if ( ! $this->validateURL( $url ) )
		{
			$this->apiDebug( 'Invalid or disallowed URL.' );
			return;
		}
		// Use cURL to perform the HTTP request.
		$curlCertBundle = $this->getSystemSetting('curl-ca-bundle');
		$curl = curl_init( $url );
		if ( $curlCertBundle != '' )
		{
			curl_setopt( $curl, CURLOPT_CAINFO, $curlCertBundle );
		}
		curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, true );
		$proxyHost = $this->getSystemSetting( 'http-proxy-host' );
		$proxyPort = $this->getSystemSetting( 'http-proxy-port' );
		if ( $proxyHost != '' && $proxyPort != '' )
		{
			curl_setopt( $curl, CURLOPT_PROXY, $proxyHost . ':' . $proxyPort );
		}
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		switch ( $method )
		{
			case 'get':
				curl_setopt( $curl, CURLOPT_HTTPGET, true );
				break;
			case 'post':
				curl_setopt( $curl, CURLOPT_POST, true );
				curl_setopt( $curl, CURLOPT_POSTFIELDS, $body );
				break;
			case 'put':
				curl_setopt( $curl, CURLOPT_CUSTOMREQUEST, 'PUT');
				curl_setopt( $curl, CURLOPT_POSTFIELDS, $body );
				break;
			case 'delete':
				curl_setopt( $curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
				break;
		}
		curl_setopt( $curl, CURLOPT_HTTPHEADER,
		             explode( "\n", str_replace( "\r\n", "\n", $headers ) ) );
		$httpResult = curl_exec( $curl );
		$responseCode = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		// Stop here if the response format is 'none', or if the HTTP response status is not 200.
		$this->apiDebug( 'Response:' );
		$this->apiDebug( '  Status: ' . $responseCode );
		if ( ( $connData['response_format'] ?? '' ) == '' || $responseCode != 200 )
		{
			$this->apiDebug( $responseCode == 200 ? 'Response not needed.' : 'Bad response code.' );
			return;
		}
		$this->apiDebug( '  Body: ' . str_replace( "\n", "\n        ", $httpResult ) );
		// Prepare the return values (if any).
		$httpReturn = [];
		$this->apiDebug( 'New data:' );
		if ( ! isset( $connData['response_field'] ) || ! is_array( $connData['response_field'] ) )
		{
			$connData['response_field'] = [];
		}
		for ( $i = 0; $i < count( $connData['response_field'] ); $i++ )
		{
			if ( $connData['response_field'][$i] == '' )
			{
				continue;
			}
			$returnValue = '';
			switch( $connData['response_type'][$i] )
			{
				case 'C': // constant value
					$returnValue = $connData['response_val'][$i];
					break;
				case 'R': // response value
					$responsePath = $connData['response_val'][$i];
					if ( isset( $connData['placeholder_response_path'] ) )
					{
						foreach ( $listPlaceholders as $placeholderName => $placeholderValue )
						{
							$responsePath =
								str_replace( $placeholderName, $placeholderValue, $responsePath );
						}
					}
					if ( $connData['response_format'] == 'J' ) // JSON
					{
						$httpProcConn = $GLOBALS['conn'];
						$httpProcQuery =
							$httpProcConn->prepare( 'SELECT JSON_UNQUOTE(JSON_EXTRACT(?,?))' );
						$httpProcQuery->bind_param( 'ss', $httpResult, $responsePath );
						$httpProcQuery->execute();
						$httpProcResult = $httpProcQuery->get_result();
						if ( $httpProcResult === false )
						{
							// invalid response path, return error value
							$returnValue = $connData['response_errval'] ?? '';
						}
						else
						{
							$returnValue = $httpProcResult->fetch_row()[0];
							if ( $returnValue === null )
							{
								$returnValue = '';
							}
							elseif ( $returnValue === true )
							{
								$returnValue = '1';
							}
							elseif ( $returnValue === false )
							{
								$returnValue = '0';
							}
						}
					}
					elseif ( $connData['response_format'] == 'X' ) // XML
					{
						try
						{
							$httpResultDOM = new \DOMDocument();
							$httpResultDOM->loadXML( $httpResult );
							$httpResultXPath = new \DOMXPath( $httpResultDOM );
							$httpResultItem = $httpResultXPath->evaluate( $responsePath );
							if ( $httpResultItem === false )
							{
								// invalid response path, return error value
								$returnValue = $connData['response_errval'] ?? '';
							}
							elseif ( $httpResultItem instanceof \DOMNodeList )
							{
								if ( $httpResultItem->length > 0 )
								{
									$returnValue = $httpResultItem->item(0)->textContent;
								}
								else
								{
									// invalid response path, return error value
									$returnValue = $connData['response_errval'] ?? '';
								}
							}
							else
							{
								$returnValue = strval( $httpResultItem );
							}
						}
						catch ( \Exception $e )
						{
							// invalid response path, return error value
							$returnValue = $connData['response_errval'] ?? '';
						}
					}
					break;
				case 'S': // server date/time
					$returnValue = date( 'Y-m-d H:i:s' );
					break;
				case 'U': // UTC date/time
					$returnValue = gmdate( 'Y-m-d H:i:s' );
					break;
			}
			$useEvent = ( $connData['response_event'][$i] ?? '' );
			$useEvent = ( $useEvent == '%' ) ? $defaultEvent : $useEvent;
			$returnItem = [ 'event' => $useEvent,
			                'field' => $connData['response_field'][$i],
			                'instance' => ( $connData['response_inst'][$i] === ''
			                                ? $defaultInstance : $connData['response_inst'][$i] ),
			                'value' => $returnValue ];
			$httpReturn[] = $returnItem;
			$this->apiDebug( '  ' . json_encode( $returnItem ) );
		}
		// Write the return values to the record.
		if ( count( $httpReturn ) > 0 )
		{
			$this->setProjectFieldValues( $recordID, $httpReturn );
		}
	}



	// Perform a SOAP WSDL request.
	function performWSDL( $connData, $recordID, $defaultInstance, $defaultEvent )
	{
		// Get the WSDL endpoint URL and function name.
		$url = $connData['url'];
		$function = $connData['function'];
		$this->apiDebug( 'SOAP/WSDL Request' );
		$this->apiDebug( 'URL: ' . $url );
		$this->apiDebug( 'Function: ' .$function );
		// Check that the URL is valid.
		if ( ! $this->validateURL( $url ) )
		{
			$this->apiDebug( 'Invalid or disallowed URL.' );
			return;
		}
		// Get the SOAP parameter names and values.
		$this->apiDebug( 'Parameters:' );
		$listParams = [];
		if ( ! isset( $connData['param_name'] ) || ! is_array( $connData['param_name'] ) )
		{
			$connData['param_name'] = [];
		}
		for ( $i = 0; $i < count( $connData['param_name'] ); $i++ )
		{
			if ( $connData['param_name'][$i] == '' )
			{
				continue;
			}
			if ( $connData['param_type'][$i] == 'C' ) // constant value
			{
				$listParams[ $connData['param_name'][$i] ] = $connData['param_val'][$i];
			}
			elseif ( $connData['param_type'][$i] == 'F' ) // project field
			{
				$useInstance =
					( $connData['param_inst'][$i] === '' ? $defaultInstance
					                                     : $connData['param_inst'][$i] );
				$useEvent = ( $connData['param_event'][$i] ?? '' );
				$useEvent = ( $useEvent == '%' ) ? $defaultEvent : $useEvent;
				$listParams[ $connData['param_name'][$i] ] =
					$this->getProjectFieldValue( $recordID, $useEvent,
					                             $connData['param_field'][$i], $useInstance,
					                             $connData['param_func'][$i],
					                             $connData['param_func_args'][$i] );
			}
			$this->apiDebug( '  ' . $connData['param_name'][$i] . ' => ' .
			                 $listParams[ $connData['param_name'][$i] ] );
		}
		// Prepare the options for the request.
		$soapOptions = [ 'cache_wsdl' => WSDL_CACHE_MEMORY ];
		$proxyHost = $this->getSystemSetting( 'http-proxy-host' );
		$proxyPort = $this->getSystemSetting( 'http-proxy-port' );
		if ( $proxyHost != '' && $proxyPort != '' )
		{
			$soapOptions['proxy_host'] = $proxyHost;
			$soapOptions['proxy_port'] = $proxyPort;
		}
		// Use SoapClient to perform the request.
		$soap = new \SoapClient( $url, $soapOptions );
		$soapResult = call_user_func( [ $soap, $function ], $listParams );
		$this->apiDebug( 'SOAP/WSDL Response:' );
		$this->apiDebug( '  ' . str_replace( "\n", "\n  ", print_r( $soapResult, true ) ) );
		// Prepare the return values (if any).
		$soapReturn = [];
		$this->apiDebug( 'New data:' );
		if ( ! isset( $connData['response_field'] ) || ! is_array( $connData['response_field'] ) )
		{
			$connData['response_field'] = [];
		}
		for ( $i = 0; $i < count( $connData['response_field'] ); $i++ )
		{
			if ( $connData['response_field'][$i] == '' )
			{
				continue;
			}
			$returnValue = '';
			switch( $connData['response_type'][$i] )
			{
				case 'C': // constant value
					$returnValue = $connData['response_val'][$i];
					break;
				case 'R': // return value
					try
					{
						$returnValue = $soapResult->{$connData['response_val'][$i]};
						if ( is_object( $returnValue ) )
						{
							$returnValue = json_encode( $returnValue );
						}
					}
					catch ( \Exception $e )
					{
						$returnValue = ''; // invalid response name, return empty string
					}
					break;
				case 'S': // server date/time
					$returnValue = date( 'Y-m-d H:i:s' );
					break;
				case 'U': // UTC date/time
					$returnValue = gmdate( 'Y-m-d H:i:s' );
					break;
			}
			$useEvent = ( $connData['response_event'][$i] ?? '' );
			$useEvent = ( $useEvent == '%' ) ? $defaultEvent : $useEvent;
			$returnItem = [ 'event' => $useEvent,
			                'field' => $connData['response_field'][$i],
			                'instance' => ( $connData['response_inst'][$i] === ''
			                                ? $defaultInstance : $connData['response_inst'][$i] ),
			                'value' => $returnValue ];
			$soapReturn[] = $returnItem;
			$this->apiDebug( '  ' . json_encode( $returnItem ) );
		}
		// Write the return values to the record.
		if ( count( $soapReturn ) > 0 )
		{
			$this->setProjectFieldValues( $recordID, $soapReturn );
		}
	}



	// Get the value of a project field.
	// $inputData is a 2-level array, where the second level array keys are 'event', 'field',
	// 'instance', and 'value', defining the fields and the data to insert.
	function setProjectFieldValues( $recordID, $inputData )
	{
		// Prepare the dataset for insert.
		$data = [ $recordID => [] ];
		$defaultEventID = array_shift(
		                        array_keys(
		                            array_shift(
		                                \REDCap::getData( [ 'project_id' => ( defined('PROJECT_ID')
		                                                              ? PROJECT_ID : $_GET['pid'] ),
		                                                    'return_format' => 'array',
		                                                    'fields' => $this->getRecordIdField(),
		                                                    'records' => $recordID ] ) ) ) );
		// Build the dataset for insert from the input data.
		foreach ( $inputData as $inputItem )
		{
			// Ensure all the metadata is present.
			if ( ! isset( $inputItem['event'], $inputItem['field'],
			              $inputItem['instance'], $inputItem['value'] ) )
			{
				continue;
			}
			// Get the event ID (if empty, use the default, e.g. for non-longitudinal projects).
			$eventID = $defaultEventID;
			if ( $inputItem['event'] != '' )
			{
				if ( defined( 'PROJECT_ID' ) )
				{
					$eventID = \REDCap::getEventIdFromUniqueEvent( $inputItem['event'] );
				}
				else
				{
					$obProj = new \Project( $_GET['pid'] );
					$eventID = $obProj->getEventIdUsingUniqueEventName( $inputItem['event'] );
				}
			}
			// Determine the instrument for the field.
			$instrument = $this->getProject()->getFormForField( $inputItem['field'] );
			// Determine if the field is on a repeating instrument/event.
			$repeatingForms = $this->getRepeatingForms( $eventID );
			$repeatInstrument = false;
			if ( count( $repeatingForms ) == 1 && $repeatingForms[0] === null )
			{
				$repeatInstrument = '';
			}
			elseif ( count( $repeatingForms ) > 0 && in_array( $instrument, $repeatingForms ) )
			{
				$repeatInstrument = $instrument;
			}
			// Add the data to the array ready for input.
			if ( $repeatInstrument === false )
			{
				// Single-instance data.
				$data[ $recordID ][ $eventID ][ $inputItem['field'] ] = $inputItem['value'];
			}
			else
			{
				// Multi-instance data.
				$totalInstances = \REDCap::getData( [ 'project_id' => ( defined('PROJECT_ID')
				                                                      ? PROJECT_ID : $_GET['pid'] ),
				                                      'return_format' => 'array',
				                                      'records' => $recordID ] );
				$totalInstances = count( $totalInstances[ $recordID ][ 'repeat_instances' ]
				                                                [ $eventID ][ $repeatInstrument ] );
				$selectedInstance = $inputItem['instance'];
				if ( $selectedInstance < 1 )
				{
					$selectedInstance = $totalInstances + $selectedInstance;
					if ( $selectedInstance < 1 )
					{
						$selectedInstance = 1;
					}
				}
				elseif ( $selectedInstance > $totalInstances )
				{
					$selectedInstance = $totalInstances;
				}
				$data[ $recordID ][ 'repeat_instances' ][ $eventID ][ $repeatInstrument ]
				                 [ $selectedInstance ][ $inputItem['field'] ] = $inputItem['value'];
			}
		}
		// Exit the function here if no data to add.
		if ( empty( $data[ $recordID ] ) )
		{
			return;
		}
		// Add the data to the record.
		\REDCap::saveData( [ 'dataFormat' => 'array', 'data' => $data, 'dateFormat' => 'YMD',
		                     'overwriteBehavior' => 'normal' ] );
	}



	// Updates the global cron list.
	function updateCronList( $projectID, $connID, $connConfig )
	{
		// Get the cron details, if applicable.
		if ( $connConfig['active'] && $connConfig['trigger'] == 'C' )
		{
			$cronDetails = [];
			foreach ( [ 'min', 'hr', 'day', 'mon', 'dow' ] as $t )
			{
				$cronDetails[$t] = $connConfig["cron_$t"];
			}
		}
		// Set a named database lock (with 20 second timeout) while updating the global cron list.
		$this->query( "DO GET_LOCK('rc-mod-apiclient-cronlist',20)", [] );
		$listCrons = $this->getSystemSetting( 'cronlist' );
		if ( $listCrons === null )
		{
			$listCrons = [];
		}
		else
		{
			$listCrons = json_decode( $listCrons, true );
		}
		if ( $connConfig['active'] && $connConfig['trigger'] == 'C' )
		{
			$listCrons["$projectID.$connID"] = $cronDetails;
		}
		else
		{
			unset( $listCrons["$projectID.$connID"] );
		}
		$this->setSystemSetting( 'cronlist', json_encode( $listCrons ) );
		// Release the named database lock.
		$this->query( "DO RELEASE_LOCK('rc-mod-apiclient-cronlist')", [] );
	}



	// Updates the global cron list with all scheduled connections for the current project.
	// Optionally remove the project's connections from the cron list.
	function updateCronListAllProjectConns( $remove = false )
	{
		$projectID = $this->getProjectID();
		if ( $projectID === null )
		{
			return;
		}

		// Set a named database lock (with 20 second timeout) while updating the global cron list.
		$this->query( "DO GET_LOCK('rc-mod-apiclient-cronlist',20)", [] );
		$listCrons = $this->getSystemSetting( 'cronlist' );
		if ( $listCrons === null )
		{
			$listCrons = [];
		}
		else
		{
			$listCrons = json_decode( $listCrons, true );
		}
		// Remove any connections for the current project from the global cron list.
		foreach ( $listCrons as $cronID => $cronDetails )
		{
			if ( substr( $cronID, 0, strlen( "$projectID." ) ) == "$projectID." )
			{
				unset( $listCrons[$cronID] );
			}
		}
		// If removal is not indicated (re-)add all the scheduled connections for the current
		// project to the global cron list.
		if ( ! $remove )
		{
			$listConnections = $this->getConnectionList();
			foreach ( $listConnections as $connID => $connConfig )
			{
				if ( $connConfig['active'] && $connConfig['trigger'] == 'C' )
				{
					$listCrons["$projectID.$connID"] = $cronDetails;
				}
			}
		}
		// Update the database and release the named database lock.
		$this->setSystemSetting( 'cronlist', json_encode( $listCrons ) );
		$this->query( "DO RELEASE_LOCK('rc-mod-apiclient-cronlist')", [] );
	}



	// Updates the configuration and data for the connection.
	function updateConnection( $connID, $connConfig, $connData )
	{
		$projectID = $this->getProjectID();
		$this->setSystemSetting( "p$projectID-conn-config-$connID", json_encode( $connConfig ) );
		$this->setSystemSetting( "p$projectID-conn-data-$connID", json_encode( $connData ) );
		if ( $connConfig['active'] && $connConfig['trigger'] == 'C' )
		{
			if ( $this->getSystemSetting( "p$projectIDconn-lastrun-$connID" ) == null )
			{
				$this->setSystemSetting( "p$projectID-conn-lastrun-$connID", time() );
			}
		}
		else
		{
			$this->removeSystemSetting( "p$projectID-conn-lastrun-$connID" );
		}
		$this->updateCronList( $projectID, $connID, $connConfig );
	}



	// Validate the URL for an API connection.
	function validateURL( $url )
	{
		if ( substr( $url, 0, 7 ) != 'http://' && substr( $url, 0, 8 ) != 'https://' )
		{
			return false;
		}
		$domain = parse_url( $url, PHP_URL_HOST );
		if ( $domain === false || $domain === null )
		{
			return false;
		}
		$allowlist = $this->getSystemSetting( 'domain-allowlist' );
		if ( $allowlist != '' )
		{
			$listDomains = explode( "\n", str_replace( "\r\n", "\n", $allowlist ) );
			if ( ! in_array( $domain, $listDomains ) )
			{
				return false;
			}
		}
		if ( $this->getSystemSetting( 'allow-rfc-1918' ) )
		{
			return true;
		}
		$ip = $domain;
		if ( ! preg_match( '/^((1?[0-9]{1,2}|2([0-4][0-9]|5[0-5]))\.){3}' .
		                   '(1?[0-9]{1,2}|2([0-4][0-9]|5[0-5]))$/', $ip ) )
		{
			$ip = gethostbyname( "$domain." );
		}
		if ( preg_match( '/^(10(\.(1?[0-9]{1,2}|2([0-4][0-9]|5[0-5]))){3}|' .
		                 '(169\.254|172\.(1[6-9]|2[0-9]|3[01])|192\.168)' .
		                 '(\.(1?[0-9]{1,2}|2([0-4][0-9]|5[0-5]))){2})$/', $ip ) )
		{
			// IPv4 in ranges 10/8, 169.254/16, 172.16/12, 192.168/16
			return false;
		}
		return true;
	}



	// CSS style for API client pages.
	function writeStyle()
	{
		$style = '
			.mod-apiclient-formtable
			{
				width: 97%;
				border: solid 1px #000;
			}
			.mod-apiclient-formtable th
			{
				padding: 5px;
				font-size: 130%;
				font-weight: bold;
			}
			.mod-apiclient-formtable td
			{
				padding: 5px;
			}
			.mod-apiclient-formtable td:first-child
			{
				width: 200px;
				padding-top: 7px;
				padding-right: 8px;
				text-align:right;
				vertical-align: top;
			}
			.mod-apiclient-formtable input:not([type=submit]):not([type=radio]):not([type=checkbox])
			{
				width: 95%;
				max-width: 600px;
			}
			.mod-apiclient-formtable textarea
			{
				width: 95%;
				max-width: 600px;
				height: 100px;
			}
			.mod-apiclient-formtable label
			{
				margin-bottom: 0px;
			}
			.mod-apiclient-formtable span.field-desc
			{
				font-size: 90%;
			}
			.mod-apiclient-listtable
			{
				border: solid 1px #000;
				border-collapse: collapse;
			}
			.mod-apiclient-listtable th
			{
				padding: 8px 5px;
				font-weight: bold;
				border: solid 1px #000;
			}
			.mod-apiclient-listtable td
			{
				padding: 3px;
				border: solid 1px #000;
			}
			';
		echo '<script type="text/javascript">',
			 '(function (){var el = document.createElement(\'style\');',
			 'el.setAttribute(\'type\',\'text/css\');',
			 'el.innerText = \'', addslashes( preg_replace( "/[\t\r\n ]+/", ' ', $style ) ), '\';',
			 'document.getElementsByTagName(\'head\')[0].appendChild(el)})()</script>';
	}


}
