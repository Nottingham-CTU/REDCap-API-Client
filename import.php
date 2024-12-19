<?php
/*
 *	Imports the API client configuration from a JSON document.
 */

namespace Nottingham\APIClient;


// Check user can edit API client connections.
if ( ! $module->canEditConnections() )
{
	exit;
}


$projectID = $module->getProjectID();


$listConfigFields = [ 'type' => [ 'Connection type', 'strtoupper' ],
                      'active' => [ 'Connection is active',
                                    function ($v) { return ($v === null ? 'Production'
                                                                        : ($v ? 'Yes' : 'No')); } ],
                      'trigger' => [ 'Connection trigger',
                                     function ($v) { return ( $v == 'R' ? 'Record save'
                                                                        : 'Schedule' ); } ],
                      'event' => 'Limit to event', 'form' => 'Limit to form',
                      'cron_min' => 'Schedule - min', 'cron_hr' => 'Schedule - hr',
                      'cron_day' => 'Schedule - day', 'cron_mon' => 'Schedule - mon',
                      'cron_dow' => 'Schedule - dow', 'condition' => 'Condition' ];


$mode = 'upload';
if ( ! empty( $_FILES ) ) // file is uploaded
{
	$mode = 'verify';
	// Check that a file has been uploaded and it is valid.
	if ( ! is_uploaded_file( $_FILES['import_file']['tmp_name'] ) )
	{
		$mode = 'error';
		$error = 'No file uploaded.';
	}
	if ( $mode == 'verify' ) // no error
	{
		$fileData = file_get_contents( $_FILES['import_file']['tmp_name'] );
		$data = json_decode( $fileData, true );
		if ( $data == null || ! is_array( $data ) || ! is_string( $data[0] ) ||
		     substr( $data[0], 0, 24 ) != 'REDCap API Client export' ||
		     ! isset( $data[1]['conn-list'] ) || ! is_array( $data[1]['conn-list'] ) )
		{
			$mode = 'error';
			$error = 'The uploaded file is not a valid API Client export.';
		}
	}
	if ( $mode == 'verify' ) // no error
	{
		$data = $data[1];
		foreach ( $data['conn-list'] as $connID )
		{
			if ( preg_match( '/[^a-z0-9_-]/', $connID ) )
			{
				$mode = 'error';
				$error = "The uploaded file contains an invalid connection ID: $connID";
				break;
			}
			if ( ! isset( $data["conn-config-$connID"] ) ||
			     ! array_key_exists( "conn-data-$connID", $data ) )
			{
				$mode = 'error';
				$error = "Data missing for connection $connID";
				break;
			}
		}
	}
	// Parse the uploaded file for differences between the existing connections and those contained
	// within the file. The user will be asked to confirm the changes.
	// Where connection IDs are different but the connections themselves are identical, the IDs in
	// the import will be updated to match the project IDs.
	// Authentication placeholder/parameter values will retain any current values in the project.
	if ( $mode == 'verify' ) // no error
	{
		$listCurrent = json_decode( $module->getSystemSetting( "p$projectID-conn-list" ),
		                            true ) ?? [];
		$listImported = $data['conn-list'] ?? [];
		$listNew = array_diff( $listImported, $listCurrent );
		$listDeleted = array_diff( $listCurrent, $listImported );
		foreach ( $listNew as $connID )
		{
			foreach ( $listDeleted as $i => $connID2 )
			{
				$currentConfig = $module->getConnectionConfig( $connID2 );
				$currentData = $module->getConnectionData( $connID2 );
				if ( $currentConfig['type'] == 'http' )
				{
					unset( $currentData['auth_ph_value'] );
				}
				elseif ( $currentConfig['type'] == 'wsdl' )
				{
					for ( $i = 0; $i < count( $currentData['param_type'] ); $i++ )
					{
						if ( $currentData['param_type'][ $i ] == 'A' &&
						     isset( $currentData['param_val'][ $i ] ) )
						{
							$currentData['param_val'][ $i ] = '';
						}
					}
				}
				if ( $currentConfig == $data["conn-config-$connID"] &&
				     $currentData == $data["conn-data-$connID"] )
				{
					// It's a match, replace the import ID with the current ID.
					$data['conn-list'][] = $connID2;
					unset( $data['conn-list'][ array_search( $connID, $data['conn-list'] ) ] );
					$data["conn-config-$connID2"] = $data["conn-config-$connID"];
					$data["conn-data-$connID2"] = $data["conn-data-$connID"];
					unset( $data["conn-config-$connID"], $data["conn-data-$connID"] );
					unset( $listDeleted[ $i ] );
					break;
				}
			}
		}
		$listImported = $data['conn-list'] ?? [];
		$listNew = array_diff( $listImported, $listCurrent );
		$listDeleted = array_diff( $listCurrent, $listImported );
		$listIdentical = [];
		$listChanged = [];
		foreach ( array_intersect( $listCurrent, $listImported ) as $connID )
		{
			$currentConfig = $module->getConnectionConfig( $connID );
			$currentData = $module->getConnectionData( $connID );
				if ( $currentConfig['type'] == 'http' && isset( $currentData['auth_ph_value'] ) )
				{
					$data["conn-data-$connID"]['auth_ph_value'] = $currentData['auth_ph_value'];
				}
				elseif ( $currentConfig['type'] == 'wsdl' )
				{
					for ( $i = 0; $i < count( $currentData['param_type'] ); $i++ )
					{
						if ( $currentData['param_type'][ $i ] == 'A' &&
						     $data["conn-data-$connID"]['param_type'][ $i ] == 'A' )
						{
							$data["conn-data-$connID"]['param_val'][ $i ] =
									$currentData['param_val'][ $i ];
						}
					}
				}
			$identicalConfig = ( $currentConfig == $data["conn-config-$connID"] );
			$identicalData = ( $currentData == $data["conn-data-$connID"] );
			if ( $identicalConfig && $identicalData )
			{
				$listIdentical[] = $connID;
			}
			else
			{
				$listChanged[] =
					[ 'id' => $connID, 'config' => !$identicalConfig, 'data' => !$identicalData ];
			}
		}
		$fileData = json_encode( $data, JSON_UNESCAPED_SLASHES );
	}
}
elseif ( ! empty( $_POST ) ) // normal POST request (confirming import)
{
	$mode = 'complete';
	// The contents of the file are passed across from the verify stage. If this is valid, the
	// selected changes are applied.
	$fileData = $_POST['import_data'];
	$data = json_decode( $fileData, true );
	if ( $data == null || ! is_array( $data ) || ! isset( $data['conn-list'] ) ||
		 ! is_array( $data['conn-list'] ) )
	{
		$mode = 'error';
		$error = 'The uploaded file data is not valid.';
	}
	if ( $mode == 'complete' ) // no error
	{
		foreach ( $_POST as $key => $val )
		{
			if ( substr( $key, 0, 9 ) == 'conn-add-' )
			{
				// Add new connection into project from file.
				$connID = substr( $key, 9 );
				$module->addConnection( $data["conn-config-$connID"],
				                        $data["conn-data-$connID"], $connID );
			}
			elseif ( substr( $key, 0, 12 ) == 'conn-update-' )
			{
				// Update connection.
				$connID = substr( $key, 12 );
				$module->updateConnection( $connID, $data["conn-config-$connID"],
				                           $data["conn-data-$connID"] );
			}
			elseif ( substr( $key, 0, 12 ) == 'conn-delete-' )
			{
				// Remove connection from project.
				$connID = substr( $key, 12 );
				$module->deleteConnection( $connID );
			}
		}
	}
}


// Display the project header
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
$module->writeStyle();


?>
<div class="projhdr">
 Import API Client Connections
</div>
<p style="font-size:11px">
 <a href="<?php echo $module->getUrl( 'connections.php' )
?>"><i class="fas fa-arrow-circle-left fs11"></i> Back to API Client</a>
</p>
<?php


// Display the file upload form.
if ( $mode == 'upload' )
{


?>
<form method="post" enctype="multipart/form-data">
 <table class="mod-apiclient-formtable">
  <tr>
   <td>Import file</td>
   <td>
    <input type="file" name="import_file">
   </td>
  </tr>
  <tr>
   <td></td>
   <td>
    <input type="submit" value="Import">
   </td>
  </tr>
 </table>
</form>
<?php


}
// Display the options to confirm the changes to the connections introduced by the file.
elseif ( $mode == 'verify' )
{


?>
<form method="post">
 <table class="mod-apiclient-formtable">
<?php
	if ( count( $listIdentical ) > 0 )
	{
?>
  <tr>
   <th colspan="2">Identical Connections</th>
  </tr>
  <tr>
   <td colspan="2" style="text-align:left">
    <ul>
<?php
		foreach ( $listIdentical as $connID )
		{
?>
     <li><?php echo $module->escapeHTML( $data["conn-config-$connID"]['label'] ); ?></li>
<?php
		}
?>
    </ul>
   </td>
  </tr>
<?php
	}

	if ( count( $listNew ) > 0 )
	{
?>
  <tr>
   <th colspan="2">New Connections</th>
  </tr>
<?php
		foreach ( $listNew as $connID )
		{
?>
  <tr>
   <td><?php echo $module->escapeHTML( $data["conn-config-$connID"]['label'] ); ?></td>
   <td>
    <input type="checkbox" name="conn-add-<?php
			echo $module->escapeHTML( $connID ); ?>" value="1" checked>
    Add this connection
    <ul>
<?php
			foreach ( $listConfigFields as $configName => $configLabel )
			{
				$configValue = $data["conn-config-$connID"][$configName];
				if ( is_array( $configLabel ) )
				{
					$configValue = $configLabel[1]( $configValue );
					$configLabel = $configLabel[0];
				}
				if ( $configValue == '' )
				{
					continue;
				}
				$configValue = nl2br( $module->escapeHTML( $configValue ) );
?>
     <li><b><?php echo $configLabel; ?>:</b> <?php echo $configValue; ?></li>
<?php
			}
?>
    </ul>
   </td>
  </tr>
<?php
		}
	}

	if ( count( $listChanged ) > 0 )
	{
?>
  <tr>
   <th colspan="2">Changed Connections</th>
  </tr>
<?php
		foreach ( $listChanged as $connChange )
		{
			$connID = $connChange['id'];
			$changed = $connChange['config'] || $connChange['data'];
?>
  <tr>
   <td><?php echo $module->escapeHTML( $data["conn-config-$connID"]['label'] ); ?></td>
   <td>
<?php
			if ( $changed )
			{
?>
    <input type="checkbox" name="conn-update-<?php
			echo $module->escapeHTML( $connID ); ?>" value="1" checked>
    Update connection
    <br>
<?php
			}
?>
    <ul>
<?php
			foreach ( $listConfigFields as $configName => $configLabel )
			{
				$configValue = [];
				$configValue['old'] = $module->getConnectionConfig( $connID, $configName );
				$configValue['new'] = $data["conn-config-$connID"][$configName];
				if ( is_array( $configLabel ) )
				{
					$configValue['old'] = $configLabel[1]( $configValue['old'] );
					$configValue['new'] = $configLabel[1]( $configValue['new'] );
					$configLabel = $configLabel[0];
				}
				if ( $configValue['old'] == '' && $configValue['new'] == '' )
				{
					continue;
				}
				$configValue['old'] = nl2br( $module->escapeHTML( $configValue['old'] ) );
				$configValue['new'] = nl2br( $module->escapeHTML( $configValue['new'] ) );

				if ( $configValue['old'] == $configValue['new'] )
				{
?>
     <li><b><?php echo $configLabel; ?>:</b> <?php echo $configValue['new']; ?></li>
<?php
				}
				else
				{
?>
     <li style="color:#c00;text-decoration:line-through"><b><?php echo $configLabel; ?>:</b> <?php
					echo $configValue['old']; ?></li>
     <li style="color:#060"><b><?php echo $configLabel; ?>:</b> <?php
					echo $configValue['new']; ?></li>
<?php
				}
			}
?>
    </ul>
   </td>
  </tr>
<?php
		}
	}

	if ( count( $listDeleted ) > 0 )
	{
?>
  <tr>
   <th colspan="2">Connections Not In Import File</th>
  </tr>
<?php
		foreach ( $listDeleted as $connID )
		{
?>
  <tr>
   <td><?php echo $module->escapeHTML( $module->getConnectionConfig( $connID, 'label' ) ); ?></td>
   <td>
    <input type="checkbox" name="conn-delete-<?php
			echo $module->escapeHTML( $connID ); ?>" value="1">
    Delete this connection
    <ul>
<?php
			foreach ( $listConfigFields as $configName => $configLabel )
			{
				$configValue = $module->getConnectionConfig( $connID, $configName );
				if ( is_array( $configLabel ) )
				{
					$configValue = $configLabel[1]( $configValue );
					$configLabel = $configLabel[0];
				}
				if ( $configValue == '' )
				{
					continue;
				}
				$configValue = nl2br( $module->escapeHTML( $configValue ) );
?>
     <li><b><?php echo $configLabel; ?>:</b> <?php echo $configValue; ?></li>
<?php
			}
?>
    </ul>
   </td>
  </tr>
<?php
		}
	}
?>
  <tr>
   <td></td>
   <td>
    <input type="submit" value="Update Selected Connections">
    <input type="hidden" name="import_data" value="<?php echo $module->escapeHTML( $fileData ); ?>">
   </td>
  </tr>
 </table>
</form>
<?php


}
// Display error message.
elseif ( $mode == 'error' )
{


?>
<p style="font-size:14px;color:#f00"><?php echo $module->escapeHTML( $error ); ?></p>
<?php


}
// Display success message.
elseif ( $mode == 'complete' )
{


?>
<p style="font-size:14px">Import complete</p>
<?php


}


// Display the project footer
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';

