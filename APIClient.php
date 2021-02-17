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
		$listIDs[] = $reportID;
		$this->setProjectSetting( 'conn-list', json_encode( $listIDs ) );
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



	// Sets the specified configuration option for a connection to the specified value.
	function setConnectionConfig( $connID, $configName, $configValue )
	{
		$connConfig = $this->getConnectionConfig( $connID );
		$connConfig[ $configName ] = $configValue;
		$this->setProjectSetting( "conn-config-$connID", json_encode( $connConfig ) );
	}



	// Sets the definition data for the specified connection.
	function setConnectionData( $connID, $connData )
	{
		$this->setProjectSetting( "conn-data-$connID", json_encode( $connData ) );
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
