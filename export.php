<?php
/*
 *	Exports the API Client configuration as a JSON document.
 */

namespace Nottingham\APIClient;


// Check user can edit API client connections.
if ( ! $module->canEditConnections() )
{
	exit;
}

header( 'Content-Type: application/json' );
header( 'Content-Disposition: attachment; filename=' .
        trim( preg_replace( '/[^A-Za-z0-9-]+/', '_', \REDCap::getProjectTitle() ), '_-' ) .
        '_apiclient_' . gmdate( 'Ymd-His' ) . '.json' );

$projectID = $module->getProjectID();
$listConnections = json_decode( $module->getSystemSetting( "p$projectID-conn-list" ), true );
$data = [ 'conn-list' => $listConnections ];

foreach ( $listConnections as $connID )
{
	$data["conn-config-$connID"] =
			json_decode( $module->getSystemSetting("p$projectID-conn-config-$connID"), true );
	$connData = json_decode( $module->getSystemSetting("p$projectID-conn-data-$connID"), true );
	if ( $data["conn-config-$connID"]['type'] == 'http' )
	{
		unset( $connData['auth_ph_value'] );
	}
	elseif( $data["conn-config-$connID"]['type'] == 'wsdl' )
	{
		for ( $i = 0; $i < count( $connData['param_type'] ); $i++ )
		{
			if ( $connData['param_type'][ $i ] == 'A' &&
			     isset( $connData['param_val'][ $i ] ) )
			{
				$connData['param_val'][ $i ] = '';
			}
		}
	}
	$data["conn-data-$connID"] = $connData;
}

echo '[', json_encode( 'REDCap API Client export (' . \REDCap::getProjectTitle() . ')' ),
     ",\n", json_encode( $data, JSON_UNESCAPED_SLASHES ), ']';