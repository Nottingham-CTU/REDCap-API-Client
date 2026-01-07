<?php
/**
 *  Outputs the status of features required for the API client module.
 */

namespace Nottingham\APIClient;

if ( $module->getProjectId() !== null )
{
	exit;
}

$proxyHost = $module->getSystemSetting( 'http-proxy-host' );
$proxyPort = $module->getSystemSetting( 'http-proxy-port' );

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
<?php

// Test 3: SOAP Connection
if ( $testStatus )
{

	$soapOptions = [ 'cache_wsdl' => WSDL_CACHE_MEMORY ];
	if ( $proxyHost != '' && $proxyPort != '' )
	{
		$soapOptions['proxy_host'] = $proxyHost;
		$soapOptions['proxy_port'] = $proxyPort;
	}
	try
	{
		$soap = new \SoapClient( 'http://www.dneonline.com/calculator.asmx?wsdl', $soapOptions );
		$soapResult = $soap->Add( [ 'intA' => 1, 'intB' => 2 ] );
		$testStatus = $soapResult->AddResult == 3;
		if ( $testStatus )
		{
			$testStatusDesc = 'The test SOAP request was performed successfully.';
		}
		else
		{
			$testStatusDesc = 'The test SOAP request could not be performed or returned an ' .
			                  'unexpected result.';
		}
	}
	catch ( \Exception $e )
	{
		$testStatus = false;
		$testStatusDesc = 'The test SOAP request could not be performed: ' .
		                  $module->escapeHTML( $e->getMessage() );
	}
?>
<div class="<?php echo $testStatus ? 'darkgreen" style="color:green' : 'red'; ?>">
 <b>Perform a test SOAP request</b>
 <br><br>
 <img src="<?php echo APP_PATH_IMAGES . ( $testStatus ? 'tick.png' : 'exclamation.png' ); ?>">
 <b><?php echo $testStatus ? 'SUCCESSFUL!' : 'ERROR'; ?></b> - <?php echo $testStatusDesc, "\n"; ?>
</div>
<?php
}


// Test 4: HTTP/REST Connection
if ( $bundleLocation != 'none' || $numCertificates > 0 )
{

	$curl = curl_init( 'https://catfact.ninja/fact' );
	if ( $curlCertBundle != '' )
	{
		curl_setopt( $curl, CURLOPT_CAINFO, $curlCertBundle );
	}
	elseif ( ini_get( 'curl.cainfo' ) == '' )
	{
		curl_setopt( $curl, CURLOPT_CAINFO, self::REDCAP_CAINFO );
	}
	curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, true );
	if ( $proxyHost != '' && $proxyPort != '' )
	{
		curl_setopt( $curl, CURLOPT_PROXY, $proxyHost . ':' . $proxyPort );
	}
	curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $curl, CURLOPT_HTTPGET, true );
	$httpResult = curl_exec( $curl );
	$responseCode = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
	$testStatus = ( $responseCode == 200 && $httpResult != '' );
	if ( $testStatus )
	{
		$testStatusDesc = 'The test HTTP/REST request was performed successfully.';
	}
	else
	{
		$testStatusDesc = 'The test HTTP/REST request could not be performed or returned an ' .
		                  'unexpected result.';
	}
?>
<div class="<?php echo $testStatus ? 'darkgreen" style="color:green' : 'red'; ?>">
 <b>Perform a test HTTP/REST request</b>
 <br><br>
 <img src="<?php echo APP_PATH_IMAGES . ( $testStatus ? 'tick.png' : 'exclamation.png' ); ?>">
 <b><?php echo $testStatus ? 'SUCCESSFUL!' : 'ERROR'; ?></b> - <?php echo $testStatusDesc, "\n"; ?>
</div>
<?php
}
?>