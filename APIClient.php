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
	function redcap_module_configure_button_display( $project_id )
	{
		return $this->framework->getUser()->isSuperUser() ? true : null;
	}



	// When the module is enabled on a project, check if there are any previously configured
	// scheduled connections to add to the global cron list.
	function redcap_module_project_enable( $version, $project_id )
	{
		$this->updateCronListAllProjectConns();
	}



	// When the module is disabled on a project, remove all the scheduled connections from the
	// global cron list.
	function redcap_module_project_disable( $version, $project_id )
	{
		$this->updateCronListAllProjectConns( true );
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
			if ( $connConfig['type'] == 'http' )
			{
				$this->performHTTP( $connData, $record, ( $isRepeating ? $repeat_instance : 0 ) );
			}
			elseif ( $connConfig['type'] == 'wsdl' )
			{
				$this->performWSDL( $connData, $record, ( $isRepeating ? $repeat_instance : 0 ) );
			}
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
			if ( ! $isMatch || $testTime <= $this->getProjectSetting( "conn-lastrun-$connID" ) )
			{
				continue;
			}
			$this->setProjectSetting( "conn-lastrun-$connID", $execTime );
			// For each record...
			foreach ( array_keys( \REDCap::getData( [ 'project_id' => $projectID,
			                                          'return_format' => 'array',
			                                          'fields' => $this->getRecordIdField() ] ) )
			          as $record )
			{
				// Check the conditional logic (if applicable).
				$connConfig = $this->getConnectionConfig( $connID );
				if ( $connConfig['condition'] != '' &&
				     \REDCap::evaluateLogic( $connConfig['condition'],
				                             $projectID, $record ) !== true )
				{
					continue;
				}
				// Perform the appropriate logic for the connection type.
				$connData = $this->getConnectionData( $connID );
				if ( $connConfig['type'] == 'http' )
				{
					$this->performHTTP( $connData, $record, 0 );
				}
				elseif ( $connConfig['type'] == 'wsdl' )
				{
					$this->performWSDL( $connData, $record, 0 );
				}
			}
		}
		$_GET['pid'] = $oldContext;
	}



	// Check if the API connections can be edited by the current user.
	function canEditConnections()
	{
		// Administrators can always edit API connections.
		if ( $this->framework->getUser()->isSuperUser() )
		{
			return true;
		}
		// Non-administrators can be allowed/denied access to edit API connections at the system or
		// project level. Check both the system and project level settings.
		$canEditPr = $this->getProjectSetting( 'allow-normal-users-project' );
		$canEditSys = $this->getSystemSetting( 'allow-normal-users' );
		$canEdit = $canEditPr == 'A' || ( $canEditPr != 'D' && $canEditSys );
		$userRights = $this->framework->getUser()->getRights();
		// Don't allow access by non-administrators without user rights.
		// (in practice, such users probably cannot access the project).
		if ( $userRights === null )
		{
			return false;
		}
		// If access is allowed for non-administrators, grant access if the user has design rights.
		if ( $canEdit && $userRights[ 'design' ] == '1' )
		{
			return true;
		}
		// Otherwise don't allow editing.
		return false;
	}



	// Add a new connection, with the specified configuration and data.
	function addConnection( $connConfig, $connData )
	{
		// Generate a new connection ID.
		$connID = '';
		$listIDs = $this->getProjectSetting( 'conn-list' );
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
		$this->setProjectSetting( "conn-config-$connID", json_encode( $connConfig ) );
		$this->setProjectSetting( "conn-data-$connID", json_encode( $connData ) );
		if ( $connConfig['active'] && $connConfig['trigger'] == 'C' )
		{
			$this->setProjectSetting( "conn-lastrun-$connID", time() );
		}
		else
		{
			$this->removeProjectSetting( "conn-lastrun-$connID" );
		}
		// Add the report to the list of reports.
		$listIDs = $this->getProjectSetting( 'conn-list' );
		if ( $listIDs === null )
		{
			$listIDs = [];
		}
		else
		{
			$listIDs = json_decode( $listIDs, true );
		}
		$listIDs[] = $connID;
		$this->setProjectSetting( 'conn-list', json_encode( $listIDs ) );
		$this->updateCronList( $this->getProjectID(), $connID, $connConfig );
	}



	// Delete the specified connection.
	function deleteConnection( $connID )
	{
		// Remove the connection configuration and data.
		$this->removeProjectSetting( "conn-config-$connID" );
		$this->removeProjectSetting( "conn-data-$connID" );
		$this->removeProjectSetting( "conn-lastrun-$connID" );
		$this->updateCronList( $this->getProjectID(), $connID, [ 'active' => false ] );
		// Remove the connection from the list of reports.
		$listIDs = $this->getProjectSetting( 'conn-list' );
		if ( $listIDs === null )
		{
			return;
		}
		$listIDs = json_decode( $listIDs, true );
		if ( ( $k = array_search( $connID, $listIDs ) ) !== false )
		{
			unset( $listIDs[$k] );
		}
		$this->setProjectSetting( 'conn-list', json_encode( $listIDs ) );
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



	// Returns a list of fields for the project.
	function getFieldList( $fieldTypes = '*' )
	{
		$listTypes = explode( ',', $fieldTypes );
		$listFields = [];
		foreach ( \REDCap::getDataDictionary( 'array' ) as $infoField )
		{
			if ( $fieldTypes == '*' || in_array( $infoField['field_type'], $listTypes ) ||
			     ( in_array( 'date', $listTypes ) && $infoField['field_type'] == 'text' &&
			       substr( $infoField['text_validation_type_or_show_slider_number'],
			               0, 4 ) == 'date' ) ||
			     ( in_array( 'datetime', $listTypes ) && $infoField['field_type'] == 'text' &&
			       substr( $infoField['text_validation_type_or_show_slider_number'],
			               0, 8 ) == 'datetime' ) )
			{
				$fieldLabel = str_replace( ["\r\n", "\n"], ' ', $infoField['field_label'] );
				$fieldLabel = trim( preg_replace( '/\\<[^<>]+\\>/', ' ', $fieldLabel ) );
				if ( strlen( $fieldLabel ) > 35 )
				{
					$fieldLabel =
						substr( $fieldLabel, 0, 25 ) . ' ... ' . substr( $fieldLabel, -8 );
				}

				$listFields[ $infoField['field_name'] ] =
					$infoField['field_name'] . ' - ' . $fieldLabel;
			}
		}
		return $listFields;
	}



	// Get the configuration for the specified connection.
	// Optionally specify the configuration option name, otherwise all options are returned.
	function getConnectionConfig( $connID, $configName = null )
	{
		$config = $this->getProjectSetting( "conn-config-$connID" );
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
		$data = $this->getProjectSetting( "conn-data-$connID" );
		if ( $data !== null )
		{
			$data = json_decode( $data, true );
		}
		return $data;
	}



	// Gets the list of connections, with the configuration data for each connection.
	function getConnectionList()
	{
		$listIDs = $this->getProjectSetting( 'conn-list' );
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
		// Return the value.
		return $data;
	}



	// Get the role name of the current user.
	function getUserRole()
	{
		$userRights = $this->framework->getUser()->getRights();
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
			return '<em>' . htmlspecialchars( $label ) . '</em>';
		}
		return '<a href="' . htmlspecialchars( $this->makeQueryURL( $variable, $value ) ) .
		       '">' . htmlspecialchars( $label ) . '</a>';
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
	function outputEventDropdown( $dropDownName, $value )
	{
		echo '<select name="', htmlspecialchars( $dropDownName ), '">';
		echo '<option value=""', ( $value == '' ? ' selected' : '' ), '></option>';
		foreach ( $this->getEventList() as $optValue => $optLabel )
		{
			echo '<option value="', htmlspecialchars( $optValue ), '"',
			     ( $value == $optValue ? ' selected' : '' ), '>',
			     htmlspecialchars( $optLabel ), '</option>';
		}
		echo '</select>';
	}



	// Output a drop-down list of fields for the project.
	function outputFieldDropdown( $dropDownName, $value, $fieldType = '*' )
	{
		echo '<select name="', htmlspecialchars( $dropDownName ), '">';
		echo '<option value=""', ( $value == '' ? ' selected' : '' ), '></option>';
		foreach ( $this->getFieldList( $fieldType ) as $optValue => $optLabel )
		{
			echo '<option value="', htmlspecialchars( $optValue ), '"',
			     ( $value == $optValue ? ' selected' : '' ), '>',
			     htmlspecialchars( $optLabel ), '</option>';
		}
		echo '</select>';
	}



	// Output a drop-down list of forms/instruments for the project.
	function outputFormDropdown( $dropDownName, $value )
	{
		echo '<select name="', htmlspecialchars( $dropDownName ), '">';
		echo '<option value=""', ( $value == '' ? ' selected' : '' ), '></option>';
		foreach ( \REDCap::getInstrumentNames() as $optValue => $optLabel )
		{
			echo '<option value="', htmlspecialchars( $optValue ), '"',
			     ( $value == $optValue ? ' selected' : '' ), '>',
			     htmlspecialchars( $optLabel ), '</option>';
		}
		echo '</select>';
	}



	// Perform a HTTP REST request.
	function performHTTP( $connData, $recordID, $defaultInstance )
	{
		// Get the URL, request method, headers and body.
		$url = $connData['url'];
		$method = $connData['method'];
		$headers = $connData['headers'];
		$body = $connData['body'];
		// Get the placeholder name/value pairs.
		$listPlaceholders = [];
		for ( $i = 0; $i < count( $connData['ph_name'] ); $i++ )
		{
			if ( $connData['ph_name'][$i] == '' )
			{
				continue;
			}
			$useInstance =
				( $connData['ph_inst'][$i] === '' ? $defaultInstance : $connData['ph_inst'][$i] );
			$placeholderValue =
				$this->getProjectFieldValue( $recordID, ( $connData['ph_event'][$i] ?? '' ),
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
		foreach ( $listPlaceholders as $placeholderName => $placeholderValue )
		{
			$url = str_replace( $placeholderName, $placeholderValue, $url );
			$method = str_replace( $placeholderName, $placeholderValue, $method );
			$headers = str_replace( $placeholderName, $placeholderValue, $headers );
			$body = str_replace( $placeholderName, $placeholderValue, $body );
		}
		// Use cURL to perform the HTTP request.
		$curlCertBundle = $this->getSystemSetting('curl-ca-bundle');
		$curl = curl_init( $url );
		if ( $curlCertBundle != '' )
		{
			curl_setopt( $curl, CURLOPT_CAINFO, $curlCertBundle );
		}
		curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, true );
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
		// Stop here if the response format is 'none', or if the HTTP response status is not 200.
		if ( ( $connData['response_format'] ?? '' ) == '' ||
		     curl_getinfo( $curl, CURLINFO_HTTP_CODE ) != 200 )
		{
			return;
		}
		// Prepare the return values (if any).
		$httpReturn = [];
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
					if ( $connData['response_format'] == 'J' ) // JSON
					{
						$httpProcConn = $GLOBALS['conn'];
						$httpProcQuery =
							$httpProcConn->prepare( 'SELECT JSON_UNQUOTE(JSON_EXTRACT(?,?))' );
						$httpProcQuery->bind_param( 'ss', $httpResult, $connData['response_val'] );
						$httpProcQuery->execute();
						$httpProcResult = $httpProcQuery->getResult();
						if ( $httpProcResult === false )
						{
							$returnValue = '[Invalid response path]';
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
							$httpResultDOM = new DOMDocument();
							$httpResultDOM->loadXML( $httpResult );
							$httpResultXPath = new DOMXPath( $httpResultDOM );
							$httpResultItem =
								$httpResultXPath->evaluate( $connData['response_val'][$i] );
							if ( $httpResultItem === false )
							{
								$returnValue = '[Invalid response path]';
							}
							elseif ( $httpResultItem instanceof DOMNodeList )
							{
								if ( $httpResultItem->length > 0 )
								{
									$returnValue = $httpResultItem->item(0)->textContent;
								}
								else
								{
									$returnValue = '[Invalid response path]';
								}
							}
							else
							{
								$returnValue = strval( $httpResultItem );
							}
						}
						catch ( Exception $e )
						{
							$returnValue = '[Invalid response path]';
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
			$returnItem = [ 'event' => ( $connData['response_event'][$i] ?? '' ),
			                'field' => $connData['response_field'][$i],
			                'instance' => ( $connData['response_inst'][$i] === ''
			                                ? $defaultInstance : $connData['response_inst'][$i] ),
			                'value' => $returnValue ];
			$httpReturn[] = $returnItem;
		}
		// Write the return values to the record.
		if ( count( $httpReturn ) > 0 )
		{
			$this->setProjectFieldValues( $recordID, $httpReturn );
		}
	}



	// Perform a SOAP WSDL request.
	function performWSDL( $connData, $recordID, $defaultInstance )
	{
		// Get the WSDL endpoint URL and function name.
		$url = $connData['url'];
		$function = $connData['function'];
		// Get the SOAP parameter names and values.
		$listParams = [];
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
				$listParams[ $connData['param_name'][$i] ] =
					$this->getProjectFieldValue( $recordID, ( $connData['param_event'][$i] ?? '' ),
					                             $connData['param_field'][$i], $useInstance,
					                             $connData['param_func'][$i],
					                             $connData['param_func_args'][$i] );
			}
		}
		// Use SoapClient to perform the request.
		$soap = new \SoapClient( $url, [ 'cache_wsdl' => WSDL_CACHE_MEMORY ] );
		$soapResult = call_user_func( [ $soap, $function ], $listParams );
		// Prepare the return values (if any).
		$soapReturn = [];
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
					catch ( Exception $e )
					{
						$returnValue = '[Invalid response name]';
					}
					break;
				case 'S': // server date/time
					$returnValue = date( 'Y-m-d H:i:s' );
					break;
				case 'U': // UTC date/time
					$returnValue = gmdate( 'Y-m-d H:i:s' );
					break;
			}
			$returnItem = [ 'event' => ( $connData['response_event'][$i] ?? '' ),
			                'field' => $connData['response_field'][$i],
			                'instance' => ( $connData['response_inst'][$i] === ''
			                                ? $defaultInstance : $connData['response_inst'][$i] ),
			                'value' => $returnValue ];
			$soapReturn[] = $returnItem;
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
		$this->setProjectSetting( "conn-config-$connID", json_encode( $connConfig ) );
		$this->setProjectSetting( "conn-data-$connID", json_encode( $connData ) );
		if ( $connConfig['active'] && $connConfig['trigger'] == 'C' )
		{
			if ( $this->getProjectSetting( "conn-lastrun-$connID" ) == null )
			{
				$this->setProjectSetting( "conn-lastrun-$connID", time() );
			}
		}
		else
		{
			$this->removeProjectSetting( "conn-lastrun-$connID" );
		}
		$this->updateCronList( $this->getProjectID(), $connID, $connConfig );
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
