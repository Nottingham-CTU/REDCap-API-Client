<?php
/**
 *	API Client connection edit page.
 */



// Check user can edit API client connections.
if ( ! $module->canEditConnections() )
{
	exit;
}


// Check connection ID is blank (create new connection), or that it exists.
$connID = $_GET['conn_id'] ?? '';
$listConnections = $module->getConnectionList();
if ( $connID !== '' && ! isset( $listConnections[$connID] ) )
{
	exit;
}
$connConfig = $listConnections[$connID];
$connData = $module->getConnectionData( $connID );



// Handle form submissions.
if ( ! empty( $_POST ) )
{
	// Validate data
	if ( str_replace( [ "\r", "\n" ], ' ',
	                  substr( strtolower( $_POST['sql_query'] ), 0, 7 ) ) != 'select ' )
	{
		exit;
	}
	$validQuery = ( mysqli_query( $conn, str_replace( '$$PROJECT$$', $module->getProjectId(),
	                                                  $_POST['sql_query'] ) ) !== false );
	if ( isset( $_SERVER['HTTP_X_RC_ADVREP_SQLCHK'] ) )
	{
		header( 'Content-Type: application/json' );
		if ( $validQuery )
		{
			echo 'true';
		}
		else
		{
			echo json_encode( mysqli_error( $conn ) );
		}
		exit;
	}
	if ( ! $validQuery )
	{
		exit;
	}

	// Save data
	$module->submitReportConfig( $reportID );
	$reportData = [ 'sql_query' => $_POST['sql_query'] ];
	$module->setReportData( $reportID, $reportData );
	header( 'Location: ' . $module->getUrl( 'reports_edit.php' ) );
	exit;
}



// Generate event/field drop down.
function fieldSelector( $name )
{
	global $module;
	if ( REDCap::isLongitudinal() )
	{
		$module->outputEventDropdown( $name . '_event[]', '' );
		echo ' ';
	}
	$module->outputFieldDropdown( $name . '_field[]', '' );
	echo ' <select name="', $name, '_func[]" style="margin-left:20px">',
	     '<option value="">Normal text</option>',
	     '<option value="date">Format date</option>',
	     '</select> <input type="text" style="width:80px" name="', $name, '_func_args[]">';
}



// Display the project header
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
$module->writeStyle();

?>
<div class="projhdr">
<?php
if ( $connID == '' )
{
?>
 API Client &#8212; New Connection
<?php
}
else
{
?>
 API Client &#8212; Edit Connection: <?php echo htmlspecialchars( $reportConfig['label'] ), "\n"; ?>
<?php
}
?>
</div>
<p style="font-size:11px">
 <a href="<?php echo $module->getUrl( 'connections.php' )
?>" class="fas fa-arrow-circle-left fs11"> Back to API client connections</a>
</p>
<form method="post" id="connform">
 <table class="mod-apiclient-formtable">
  <tbody>
   <tr><th colspan="2">Connection Configuration</th></tr>
   <tr>
    <td>Connection Label</td>
    <td>
     <input type="text" name="conn_label" required
            value="<?php echo htmlspecialchars( $connConfig['label'] ); ?>">
    </td>
   </tr>
   <tr>
    <td>Connection Type</td>
    <td>
     <select name="conn_type" required>
      <option value=""></option>
      <option value="http"<?php echo $connConfig['type'] == 'http'
                                     ? ' selected' : ''; ?>>HTTP / REST</option>
      <option value="wsdl"<?php echo $connConfig['type'] == 'wsdl'
                                     ? ' selected' : ''; ?>>SOAP (WSDL)</option>
     </select>
    </td>
   </tr>
   <tr>
    <td>Connection is active</td>
    <td>
     <label>
      <input type="radio" name="conn_active" value="Y" required<?php
		echo $connConfig['active'] ? ' checked' : ''; ?>> Yes
     </label>
     <br>
     <label>
      <input type="radio" name="conn_active" value="N" required<?php
		echo $connConfig['active'] ? '' : ' checked'; ?>> No
     </label>
    </td>
   </tr>
   <tr>
    <td>Trigger Connection</td>
    <td>
     <label>
      <input type="radio" name="conn_trigger" value="R" required<?php
		echo ($connConfig['trigger'] ?? 'R') == 'R' ? ' checked' : ''; ?>> On record save
     </label>
     <br>
     <label>
      <input type="radio" name="conn_trigger" value="" required<?php
		echo $connConfig['trigger'] == 'C' ? ' checked' : ''; ?>> On schedule
     </label>
    </td>
   </tr>
   <tr>
    <td>Limit to event/form</td>
    <td>
     <?php $module->outputEventDropdown( 'conn_event', $connConfig['event'] ); echo "\n"; ?>
     <?php $module->outputFormDropdown( 'conn_form', $connConfig['form'] ); echo "\n"; ?>
    </td>
   </tr>
   <tr>
    <td>Check conditional logic</td>
    <td>
     <textarea name="conn_condition" spellcheck="false"
               style="height:75px;max-width:95%;font-family:monospace;white-space:pre"><?php
echo $connConfig['condition'] ?? ''; ?></textarea>
    </td>
   </tr>
  </tbody>
  <tbody class="conn_sec conn_sec_http">
   <tr><th colspan="2">HTTP Endpoint / Request</th></tr>
   <tr>
    <td>URL</td>
    <td>
     <input type="text" style="max-width:95%" name="http_url" class="field_req field_req_http"
            value="<?php echo htmlspecialchars( $connData['url'] ); ?>">
    </td>
   </tr>
   <tr>
    <td>HTTP Method</td>
    <td>
     <select name="http_method">
      <option value="get"<?php echo $connConfig['type'] == 'http' && $connData['method'] == 'get'
                                    ? ' selected' : ''; ?>>GET</option>
      <option value="post"<?php echo $connConfig['type'] == 'http' && $connData['method'] == 'post'
                                     ? ' selected' : ''; ?>>POST</option>
      <option value="put"<?php echo $connConfig['type'] == 'http' && $connData['method'] == 'put'
                                    ? ' selected' : ''; ?>>PUT</option>
      <option value="delete"<?php echo $connConfig['type'] == 'http' &&
                                       $connData['method'] == 'delete'
                                       ? ' selected' : ''; ?>>DELETE</option>
     </select>
    </td>
   </tr>
   <tr>
    <td>Request Headers</td>
    <td>
     <textarea name="http_headers" spellcheck="false"
               style="height:200px;max-width:95%;font-family:monospace;white-space:pre"><?php
echo $connData['headers'] ?? ''; ?></textarea>
    </td>
   </tr>
   <tr>
    <td>Request Body</td>
    <td>
     <textarea name="http_body" spellcheck="false"
               style="height:300px;max-width:95%;font-family:monospace;white-space:pre"><?php
echo $connData['body'] ?? ''; ?></textarea>
    </td>
   </tr>
   <tr><th colspan="2">Placeholders</th></tr>
   <tr>
    <td></td>
    <td>
     Any defined placeholder names will be replaced in the URL, headers and body by the placeholder
     values.
    </td>
   </tr>
   <tr>
    <td></td>
    <td><a href="#" class="fas fa-plus-circle fs12" id="http_add_ph"> Add placeholder</a></td>
   </tr>
  </tbody>
  <tbody class="conn_sec conn_sec_wsdl">
   <tr><th colspan="2">SOAP (WSDL) Endpoint</th></tr>
   <tr>
    <td>WSDL URL</td>
    <td>
     <input type="text" style="max-width:95%" name="wsdl_url" class="field_req field_req_wsdl"
            value="<?php echo htmlspecialchars( $connData['url'] ); ?>">
    </td>
   </tr>
   <tr>
    <td>Function Name</td>
    <td>
     <input type="text" name="wsdl_function" class="field_req field_req_wsdl"
            value="<?php echo htmlspecialchars( $connData['function'] ); ?>">
    </td>
   </tr>
   <tr><th colspan="2">SOAP (WSDL) Parameters</th></tr>
   <tr>
    <td></td>
    <td><a href="#" class="fas fa-plus-circle fs12" id="wsdl_add_param"> Add parameter</a></td>
   </tr>
  </tbody>
  <tbody>
   <tr><td colspan="2">&nbsp;</td></tr>
   <tr>
    <td></td>
    <td>
     <input type="submit" value="Save Connection">
    </td>
   </tr>
  </tbody>
 </table>
</form>
<script type="text/javascript">
 $( function()
 {
   $('select[name="conn_type"]').change( function()
   {
     var vOption = $('select[name="conn_type"]').val()
     $('.conn_sec').css('display','none')
     $('.conn_sec_' + vOption).css('display','')
     $('.field_req').prop('required',false)
     $('.field_req_' + vOption).prop('required',true)
   })
   $('select[name="conn_type"]').change()
   $('select[name="http_method"]').change( function()
   {
     var vOption = $('select[name="http_method"]').val()
     var vHide = ( vOption == 'get' || vOption == 'delete' )
     var vBodyField = $('textarea[name="http_body"]')
     vBodyField.prop('disabled', vHide)
     vBodyField.parent().parent().css('display', vHide ? 'none' : '')
   })
   $('select[name="http_method"]').change()
   $('#http_add_ph').click( function()
   {
     var vPrev = $('#http_add_ph').parent().parent().prev()
     var vNum = vPrev.data('index')
     if ( vNum == undefined )
     {
       vNum = 0
     }
     vNum++
     var vNew = $('<tr data-index="' + vNum + '"><td>Placeholder ' + vNum + '</td><td>' +
                  'Name:<br><input type="text" name="http_ph_name[]"><br>Value:<br>' +
                  '<?php fieldSelector('http_ph'); ?><br>Format:<br>' +
                  '<select name="http_ph_format[]"><option value="">Raw value</option>' +
                  '<option value="base64">Base 64</option><option value="url">URL encode</option>' +
                  '</select></td></tr>')
     vNew.insertAfter( vPrev )
     return false
   })
   $('#wsdl_add_param').click( function()
   {
     var vPrev = $('#wsdl_add_param').parent().parent().prev()
     var vNum = vPrev.data('index')
     if ( vNum == undefined )
     {
       vNum = 0
     }
     vNum++
     var vNew = $('<tr data-index="' + vNum + '"><td>Parameter ' + vNum + '</td><td>' +
                  'Name:<br><input type="text" name="wsdl_param_name[]"><br>Type:<br>' +
                  '<select name="wsdl_param_type[]"><option value="C">Constant value</option>' +
                  '<option value="F">Project field</option></select><br><span>Value:<br>' +
                  '<input type="text" name="wsdl_param_val[]"></span><span>Field:<br>' +
                  '<?php fieldSelector('wsdl_param'); ?>' +
                  '</span></td></tr>')
     vNew.find('span').slice(1).css('display','none')
     vNew.find('select[name="wsdl_param_type[]"]').change(function(){
       var vOption = vNew.find('select[name="wsdl_param_type[]"]').val()
       var vSpan = vNew.find('span')
       vSpan.eq(0).css('display', vOption == 'C' ? '' : 'none')
       vSpan.eq(1).css('display', vOption == 'F' ? '' : 'none')
     })
     vNew.insertAfter( vPrev )
     return false
   })
 })
</script>
<?php

// Display the project footer
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
