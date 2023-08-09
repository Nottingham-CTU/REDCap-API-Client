<?php
/**
 *  Outputs the status of features required for the API client module.
 */

namespace Nottingham\APIClient;

if ( $module->getProjectId() !== null )
{
	exit;
}

?>
<h4 style="margin-top:0"><i class="fas fa-clipboard-check"></i> API Client Configuration Check</h4>
<p>
 This page will test your API Client configuration to determine if any errors exist that might
 prevent it from functioning properly.
</p>
<?php

// Test 1: cURL CA bundle

$curlCertBundle = $module->getSystemSetting('curl-ca-bundle');
$bundleLocation = 'none';
if ( $curlCertBundle != '' )
{
	$bundleLocation = 'custom';
}
elseif ( ini_get( 'curl.cainfo' ) == '' && file_exists( ini_get( 'curl.cainfo' ) ) )
{
	$curlCertBundle = ini_get( 'curl.cainfo' );
	$bundleLocation = 'system';
}
elseif ( file_exists( APIClient::REDCAP_CAINFO ) )
{
	$curlCertBundle = APIClient::REDCAP_CAINFO;
	$bundleLocation = 'redcap';
}

if ( $bundleLocation != 'none' )
{
	$bundleContent = file_get_contents( $curlCertBundle );
	$numCertificates = preg_match_all( '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
	                                   $bundleContent );
}

if ( $bundleLocation == 'none' || $numCertificates == 0 )
{
	$testStatus = false;
	$testStatusDesc = 'A cURL CA bundle file could not be found or does not contain any ' .
	                  'certificates.<br>You will not be able to make requests to HTTPS endpoints ' .
	                  'with the API client.';
}
else
{
	$testStatus = true;
	if ( $bundleLocation == 'system' )
	{
		$testStatusDesc = 'The API Client is using the system cURL CA bundle file (as defined in ' .
		                  'php.ini), which contains ' . $numCertificates . ' certificates.';
	}
	elseif ( $bundleLocation == 'redcap' )
	{
		$testStatusDesc = 'The API Client is using the cURL CA bundle file included with REDCap, ' .
		                  'which contains ' . $numCertificates . ' certificates.';
	}
	elseif ( $bundleLocation == 'custom' )
	{
		$testStatusDesc = 'The API Client is using the cURL CA bundle file specified in the ' .
		                  'module settings, which contains ' . $numCertificates . ' certificates.';
	}
}
?>
<div class="<?php echo $testStatus ? 'darkgreen" style="color:green' : 'red'; ?>">
 <b>Check cURL CA bundle file</b>
 <br><br>
 <img src="<?php echo APP_PATH_IMAGES . ( $testStatus ? 'tick.png' : 'exclamation.png' ); ?>">
 <b><?php echo $testStatus ? 'SUCCESSFUL!' : 'ERROR'; ?></b> - <?php echo $testStatusDesc, "\n"; ?>
</div>
<?php

// Test 2: SOAP Extension

$testStatus = class_exists( '\SoapClient' );
if ( $testStatus )
{
	$testStatusDesc = 'The SOAP extension is installed.';
}
else
{
	$testStatusDesc = 'The SOAP extension is not installed.';
}
?>
<div class="<?php echo $testStatus ? 'darkgreen" style="color:green' : 'red'; ?>">
 <b>Check if PHP SOAP extension is installed</b>
 <br><br>
 <img src="<?php echo APP_PATH_IMAGES . ( $testStatus ? 'tick.png' : 'exclamation.png' ); ?>">
 <b><?php echo $testStatus ? 'SUCCESSFUL!' : 'ERROR'; ?></b> - <?php echo $testStatusDesc, "\n"; ?>
</div>
