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
		$listConnections = $this->getConnectionList();
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
			if ( ( $connConfig['event'] != '' && $connConfig['event'] != $connEventID ) ||
			     ( $connConfig['form'] != '' && $connConfig['form'] != $instrument ) )
			{
				continue;
			}
			// Check the conditional logic (if applicable).
			if ( $connConfig['condition'] != '' &&
			     ! in_array( $record, $this->getRecordsMatchingCondition( $connConfig['condition'] ) ) )
			{
				continue;
			}
			// Perform the appropriate logic for the connection type.
			$connData = $this->getConnectionData( $connID );
			if ( $connConfig['type'] == 'http' )
			{
				$this->performHTTP( $connData, $record );
			}
			elseif ( $connConfig['type'] == 'wsdl' )
			{
				$this->performWSDL( $connData, $record );
			}
		}
	}



	// Apply any relevant connections using cron job.
	function runCron( $infoCron )
	{
		$execTime = time();
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
		$this->updateCronList( $this->getProjectID(), $connID, $connData );
	}



	// Delete the specified connection.
	function deleteConnection( $connID )
	{
		// Remove the connection configuration and data.
		$this->removeProjectSetting( "conn-config-$connID" );
		$this->removeProjectSetting( "conn-data-$connID" );
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
				$listFields[ $infoField['field_name'] ] =
					$infoField['field_name'] . ' - ' . $infoField['field_label'];
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
	function getProjectFieldValue( $recordID, $eventName, $fieldName,
	                               $funcName = '', $funcParams = '' )
	{
		// Get the event ID from the event name (if applicable).
		$eventID = null;
		if ( $eventName != '' )
		{
			$eventID = \REDCap::getEventIdFromUniqueEvent( $eventName );
		}
		// Get the value for the (event and) field.
		$data = \REDCap::getData( [ 'return_format' => 'array', 'records' => $recordID,
		                            'fields' => $fieldName, 'events' => $eventID,
		                            'combine_checkbox_values' => true ] );
		$data = $data[$recordID];
		if ( $eventID == null )
		{
			$data = array_shift( $data );
		}
		else
		{
			$data = $data[$eventID];
		}
		$data = $data[$fieldName];
		// If applicable, apply a function to the value.
		if ( $funcName == 'date' )
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



	// Get the records matching the condition.
	function getRecordsMatchingCondition( $condition )
	{
		$fieldName = \REDCap::getRecordIdField();
		$data = json_decode( \REDCap::getData( [ 'return_format' => 'json',
		                                         'fields' => $fieldName,
		                                         'filterLogic' => $condition ] ), true );
		$listRecords = [];
		foreach ( $data as $item )
		{
			$listRecords[ $item[$fieldName] ] = true;
		}
		return array_keys( $listRecords );
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
	function performHTTP( $connData, $recordID )
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
			$placeholderValue =
				$this->getProjectFieldValue( $recordID, ( $connData['ph_event'][$i] ?? '' ),
				                             $connData['ph_field'][$i], $connData['ph_func'][$i],
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
		$curl = curl_init( $url );
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
	}



	// Perform a SOAP WSDL request.
	function performWSDL( $connData, $recordID )
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
				$listParams[ $connData['param_name'][$i] ] =
					$listParams[ $connData['param_val'][$i] ];
			}
			elseif ( $connData['param_type'][$i] == 'F' ) // project field
			{
				$listParams[ $connData['param_name'][$i] ] =
					$this->getProjectFieldValue( $recordID, ( $connData['param_event'][$i] ?? '' ),
					                             $connData['param_field'][$i],
					                             $connData['param_func'][$i],
					                             $connData['param_func_args'][$i] );
			}
		}
		// Use SoapClient to perform the request.
		$soap = new SoapClient( $url, [ 'cache_wsdl' => WSDL_CACHE_MEMORY ] );
		$soapResult = call_user_func( [ $soap, $function ], $listParams );
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
		if ( $connConfig['active'] && $connConfig['trigger'] == 'C' )
		{
			$listCrons["$projectID.$connID"] = $cronDetails;
		}
		else
		{
			unset( $listCrons["$projectID.$connID"] );
		}
		$this->setSystemSetting( 'cronlist', $listCrons );
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
		$this->setSystemSetting( 'cronlist', $listCrons );
		$this->query( "DO RELEASE_LOCK('rc-mod-apiclient-cronlist')", [] );
	}



	// Updates the configuration and data for the connection.
	function updateConnection( $connID, $connConfig, $connData )
	{
		$this->setProjectSetting( "conn-config-$connID", json_encode( $connConfig ) );
		$this->setProjectSetting( "conn-data-$connID", json_encode( $connData ) );
		$this->updateCronList( $this->getProjectID(), $connID, $connData );
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
