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
	// If indicated, check conditional logic.
	if ( isset( $_POST[ 'checklogic' ] ) )
	{
		header( 'Content-Type: application/json' );
		echo \LogicTester::isValid( $_POST['checklogic'] ) ? 'true' : 'false';
		exit;
	}
	// If indicated, delete the connection.
	if ( isset( $_POST[ 'conn_delete' ] ) )
	{
		$module->deleteConnection( $connID );
		header( 'Location: ' . $module->getUrl( 'connections.php' ) );
		exit;
	}
	// Save data
	$submitConfig = [];
	$submitData = [];
	$submitTypePrefix = $_POST[ 'conn_type' ] . '_';
	foreach ( $_POST as $submitVar => $submitVal )
	{
		if ( substr( $submitVar, 0, 5 ) == 'conn_' )
		{
			if ( $submitVar == 'conn_active' )
			{
				$submitConfig[ 'active' ] = ( $submitVal == 'Y' );
			}
			else
			{
				$submitConfig[ substr( $submitVar, 5 ) ] = $submitVal;
			}
		}
		elseif ( substr( $submitVar, 0, strlen( $submitTypePrefix ) ) == $submitTypePrefix )
		{
			$submitData[ substr( $submitVar, strlen( $submitTypePrefix ) ) ] = $submitVal;
		}
	}
	if ( $connID == '' )
	{
		$module->addConnection( $submitConfig, $submitData );
	}
	else
	{
		$module->updateConnection( $connID, $submitConfig, $submitData );
	}
	header( 'Location: ' . $module->getUrl( 'connections.php' ) );
	exit;
}



// Generate event/field drop down.
function fieldSelector( $name, $incFunc = true )
{
	global $module;
	ob_start();
	if ( REDCap::isLongitudinal() )
	{
		$module->outputEventDropdown( $name . '_event[]', '', true );
		echo ' ';
	}
	$module->outputFieldDropdown( $name . '_field[]', '' );
	echo addslashes( ob_get_clean() );
	echo ' <input type="text" name="', $module->escapeHTML( $name ), '_inst[]"',
		 ' pattern="^(0|-?[1-9][0-9]*)?$" style="width:60px" title="Enter instance number">';
	if ( $incFunc )
	{
		echo ' <select name="', $name, '_func[]" style="margin-left:20px">',
		     '<option value="">Normal text</option>',
		     '<option value="date">Format date</option>',
		     '<option value="getline">Get line</option>',
		     '<option value="concatlines">Concatenate lines</option>',
		     '</select> <input type="text" style="width:90px" name="', $name, '_func_args[]"',
		     ' title="Enter function parameters">';
	}
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
 API Client &#8212; Edit Connection: <?php echo $module->escapeHTML( $reportConfig['label'] ), "\n"; ?>
<?php
}
?>
</div>
<p style="display:flex;justify-content:space-between;width:97%;max-width:97%">
 <a href="<?php echo $module->getUrl( 'connections.php' )
?>"><i class="fas fa-arrow-circle-left fs12"></i> Back to API client connections</a>
<?php
if ( $connID != '' )
{
?>
 <a href="#" onclick="$('#delconnform').submit();return false"
    style="color:#c00"><i class="fas fa-trash fs12"></i> Delete connection</a>
<?php
}
?>
</p>
<?php
if ( $connID != '' )
{
?>
<form method="post" id="delconnform"
      onsubmit="return confirm('Are you sure you want to delete this connection?')">
 <input type="hidden" name="conn_delete" value="1">
</form>
<?php
}
?>
<form method="post" id="connform">
 <table class="mod-apiclient-formtable">
  <tbody>
   <tr><th colspan="2">Connection Configuration</th></tr>
   <tr>
    <td>Connection Label</td>
    <td>
     <input type="text" name="conn_label" required
            value="<?php echo $module->escapeHTML( $connConfig['label'] ); ?>">
    </td>
   </tr>
   <tr>
    <td>Connection Type</td>
    <td>
     <select name="conn_type" required>
      <option value=""></option>
<?php
foreach ( $module->getConnectionTypes() as $connTypeID => $connTypeName )
{
?>
      <option value="<?php echo $connTypeID; ?>"<?php echo $connConfig['type'] == $connTypeID
                                     ? ' selected' : ''; ?>><?php echo $connTypeName; ?></option>
<?php
}
?>
     </select>
    </td>
   </tr>
   <tr>
    <td>Connection is active</td>
    <td>
     <label>
      <input type="radio" name="conn_active" value="Y" required<?php
		echo ($connConfig['active'] ?? true) ? ' checked' : ''; ?>> Yes
     </label>
     <br>
     <label>
      <input type="radio" name="conn_active" value="N" required<?php
		echo ($connConfig['active'] ?? true) ? '' : ' checked'; ?>> No
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
      <input type="radio" name="conn_trigger" value="C" required<?php
		echo $connConfig['trigger'] == 'C' ? ' checked' : ''; ?>> On schedule
     </label>
    </td>
   </tr>
   <tr class="conn_field_limit">
    <td>Limit to event/form</td>
    <td>
     <?php
if ( REDCap::isLongitudinal() )
{
	$module->outputEventDropdown( 'conn_event', $connConfig['event'] );
	echo "\n";
}
else
{
	echo '<input type="hidden" name="conn_event" value="">', "\n";
}
?>
     <?php $module->outputFormDropdown( 'conn_form', $connConfig['form'] ); echo "\n"; ?>
    </td>
   </tr>
   <tr class="conn_field_cron">
    <td>Schedule</td>
    <td>
     <input type="text" name="conn_cron_min" style="width:50px" placeholder="min"
            title="Minutes after the hour" pattern="[1-5]?[0-9]" value="<?php
		echo $module->escapeHTML( $connConfig['cron_min'] ); ?>">
     <input type="text" name="conn_cron_hr" style="width:50px" placeholder="hr"
            title="Hour of the day" pattern="1?[0-9]|2[0-3]" value="<?php
		echo $module->escapeHTML( $connConfig['cron_hr'] ); ?>">
     <input type="text" name="conn_cron_day" style="width:50px" placeholder="day"
            title="Day of the month (* = all)" pattern="[1-9]|[12][0-9]|3[01]|\*" value="<?php
		echo $module->escapeHTML( $connConfig['cron_day'] ); ?>">
     <input type="text" name="conn_cron_mon" style="width:50px" placeholder="mon"
            title="Month (* = all)" pattern="[1-9]|1[012]|\*" value="<?php
		echo $module->escapeHTML( $connConfig['cron_mon'] ); ?>">
     <input type="text" name="conn_cron_dow" style="width:50px" placeholder="dow"
            title="Day of week (0 = Sunday, 6 = Saturday, * = all)" pattern="[0-6]|\*" value="<?php
		echo $module->escapeHTML( $connConfig['cron_dow'] ); ?>">
     <br>
     (Schedule time is approximate)
    </td>
   </tr>
<?php
if ( REDCap::isLongitudinal() )
{
?>
   <tr class="conn_field_allev">
    <td>Events</td>
    <td>
     <label>
      <input type="checkbox" name="conn_all_events" value="1"<?php
	echo $connConfig['type'] == 'http' && isset( $connConfig['all_events'] )
	     ? ' checked' : '' ?>>
      Run separately for each event
     </label>
    </td>
   </tr>
<?php
}
?>
   <tr>
    <td>Check conditional logic</td>
    <td>
     <textarea name="conn_condition" spellcheck="false"
               style="height:75px;max-width:95%;font-family:monospace;white-space:pre"><?php
echo $connConfig['condition'] ?? ''; ?></textarea>
     <span id="condition_msg" style="color:#c00"></span>
    </td>
   </tr>
  </tbody>
  <tbody class="conn_sec conn_sec_http">
   <tr><th colspan="2">HTTP Endpoint / Request</th></tr>
   <tr>
    <td>URL</td>
    <td>
     <input type="text" style="max-width:95%" name="http_url" class="field_req field_req_http"
            value="<?php echo $module->escapeHTML( $connData['url'] ); ?>">
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
     <br>
     <input type="checkbox" name="http_placeholder_response_path" value="1"<?php
echo $connConfig['type'] == 'http' && isset( $connData['placeholder_response_path'] )
     ? ' checked' : '' ?>>
     Also replace defined placeholder names in response value paths.
    </td>
   </tr>
   <tr>
    <td></td>
    <td><a href="#" id="http_add_ph"><i class="fas fa-plus-circle fs12"></i> Add placeholder</a></td>
   </tr>
   <tr><th colspan="2">Response Fields</th></tr>
   <tr>
    <td>Response Format</td>
    <td>
     <select name="http_response_format">
      <option value="">None / Ignore</option>
      <option value="J"<?php echo $connConfig['type'] == 'http' &&
                                  $connData['response_format'] == 'J'
                                  ? ' selected' : ''; ?>>JSON</option>
      <option value="X"<?php echo $connConfig['type'] == 'http' &&
                                  $connData['response_format'] == 'X'
                                  ? ' selected' : ''; ?>>XML</option>
     </select>
    </td>
   </tr>
   <tr>
    <td></td>
    <td>
     <a href="#" id="http_add_response"><i class="fas fa-plus-circle fs12"></i> Add response field</a>
    </td>
   </tr>
   <tr>
    <td>Value if Error</td>
    <td>
     <input type="text" name="http_response_errval" value="<?php
echo $module->escapeHTML( $connData['response_errval'] ?? '' ); ?>">
    </td>
   </tr>
  </tbody>
  <tbody class="conn_sec conn_sec_wsdl">
   <tr><th colspan="2">SOAP (WSDL) Endpoint</th></tr>
   <tr>
    <td>WSDL URL</td>
    <td>
     <input type="text" style="max-width:95%" name="wsdl_url" class="field_req field_req_wsdl"
            value="<?php echo $module->escapeHTML( $connData['url'] ); ?>">
    </td>
   </tr>
   <tr>
    <td>Function Name</td>
    <td>
     <input type="text" name="wsdl_function" class="field_req field_req_wsdl"
            value="<?php echo $module->escapeHTML( $connData['function'] ); ?>">
    </td>
   </tr>
   <tr><th colspan="2">SOAP (WSDL) Parameters</th></tr>
   <tr>
    <td></td>
    <td>
     <a href="#" id="wsdl_add_param"><i class="fas fa-plus-circle fs12"></i> Add parameter</a>
    </td>
   </tr>
   <tr><th colspan="2">Response Fields</th></tr>
   <tr>
    <td></td>
    <td>
     <a href="#" id="wsdl_add_response"><i class="fas fa-plus-circle fs12"></i> Add response field</a>
    </td>
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
   $('input[name="conn_trigger"]').click( function()
   {
     var vOption = $('input[name="conn_trigger"]:checked').val()
     $('.conn_field_limit').css('display', vOption == 'R' ? '' : 'none')
     $('.conn_field_cron').css('display', vOption == 'C' ? '' : 'none')
     $('.conn_field_allev').css('display', vOption == 'C' ? '' : 'none')
     $('.conn_field_cron input').prop('required', vOption == 'C')
     if ( vOption != 'R' )
     {
       $('.conn_field_limit select').val('')
     }
     if ( vOption != 'C' )
     {
       $('.conn_field_cron input').val('')
       $('.conn_field_allev input').prop('checked',false)
     }
   })
   $('textarea[name="conn_condition"]').change( function()
   {
     var vCondValue = $('textarea[name="conn_condition"]')[0].value
     if ( vCondValue == '' )
     {
       $('#condition_msg').html('')
       return
     }
     $.post( '', { checklogic : vCondValue },
             function( val )
             {
               if ( val )
               {
                 $('#condition_msg').html('')
               }
               else
               {
                 $('#condition_msg').html('<br><i class="fas fa-exclamation-triangle"></i> ' +
                                          'Invalid conditional logic!')
               }
             }, 'json')
   })
   $('input[name="conn_trigger"]:checked').click()
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
   $('#http_add_response').click( function()
   {
     var vPrev = $('#http_add_response').parent().parent().prev()
     var vNum = vPrev.data('index')
     if ( vNum == undefined )
     {
       vNum = 0
     }
     vNum++
     var vNew = $('<tr data-index="' + vNum + '"><td>Response Field ' + vNum + '</td><td>' +
                  'Field:<br><?php fieldSelector('http_response', false); ?><br>Type:<br>' +
                  '<select name="http_response_type[]"><option value="C">Constant value</option>' +
                  '<option value="R">Response value</option>' +
                  '<option value="S">Server date/time</option>' +
                  '<option value="U">UTC date/time</option></select><br><span>Value:<br>' +
                  '<input type="text" name="http_response_val[]"></span></td></tr>')
     vNew.find('select[name="http_response_type[]"]').change(function(){
       var vOption = vNew.find('select[name="http_response_type[]"]').val()
       var vSpan = vNew.find('span')
       vSpan.eq(0).css('display', ( vOption == 'C' || vOption == 'R' ) ? '' : 'none')
     })
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
   $('#wsdl_add_response').click( function()
   {
     var vPrev = $('#wsdl_add_response').parent().parent().prev()
     var vNum = vPrev.data('index')
     if ( vNum == undefined )
     {
       vNum = 0
     }
     vNum++
     var vNew = $('<tr data-index="' + vNum + '"><td>Response Field ' + vNum + '</td><td>' +
                  'Field:<br><?php fieldSelector('wsdl_response', false); ?><br>Type:<br>' +
                  '<select name="wsdl_response_type[]"><option value="C">Constant value</option>' +
                  '<option value="R">Return value</option>' +
                  '<option value="S">Server date/time</option>' +
                  '<option value="U">UTC date/time</option></select><br><span>Value:<br>' +
                  '<input type="text" name="wsdl_response_val[]"></span></td></tr>')
     vNew.find('select[name="wsdl_response_type[]"]').change(function(){
       var vOption = vNew.find('select[name="wsdl_response_type[]"]').val()
       var vSpan = vNew.find('span')
       vSpan.eq(0).css('display', ( vOption == 'C' || vOption == 'R' ) ? '' : 'none')
     })
     vNew.insertAfter( vPrev )
     return false
   })
<?php
if ( $connConfig['type'] == 'http' )
{
	$placeholders = [ 'name' => $connData['ph_name'] ?? '', 'event' => $connData['ph_event'] ?? '',
	                  'field' => $connData['ph_field'] ?? '', 'inst' => $connData['ph_inst'] ?? '',
	                  'func' => $connData['ph_func'] ?? '', 'args' => $connData['ph_func_args'] ?? '',
	                  'format' => $connData['ph_format'] ?? '' ];
?>
   var vPlaceholders = JSON.parse( '<?php echo addslashes( json_encode( $placeholders ) ); ?>' )
   for ( var i = 0, j = 0; i < vPlaceholders['name'].length; i++ )
   {
     if ( vPlaceholders['name'][i] == '' && vPlaceholders['field'][i] == '' )
     {
       continue
     }
     j++
     $('#http_add_ph').click()
     $('tr[data-index="'+(j)+'"] input[name="http_ph_name[]"]').val(vPlaceholders['name'][i])
     $('tr[data-index="'+(j)+'"] select[name="http_ph_event[]"]').val(vPlaceholders['event'][i])
     $('tr[data-index="'+(j)+'"] select[name="http_ph_field[]"]').val(vPlaceholders['field'][i])
     $('tr[data-index="'+(j)+'"] input[name="http_ph_inst[]"]').val(vPlaceholders['inst'][i])
     $('tr[data-index="'+(j)+'"] select[name="http_ph_func[]"]').val(vPlaceholders['func'][i])
     $('tr[data-index="'+(j)+'"] input[name="http_ph_func_args[]"]').val(vPlaceholders['args'][i])
     $('tr[data-index="'+(j)+'"] select[name="http_ph_format[]"]').val(vPlaceholders['format'][i])
   }
<?php
	$resps = [ 'event' => $connData['response_event'] ?? '', 'field' => $connData['response_field'] ?? '',
	           'inst' => $connData['response_inst'] ?? '', 'name' => $connData['response_name'] ?? '',
	           'type' => $connData['response_type'] ?? '', 'val' => $connData['response_val'] ?? '' ];
?>
   var vResps = JSON.parse( '<?php echo addslashes( json_encode( $resps ) ); ?>' )
   for ( var i = 0, j = 0; i < vResps['field'].length; i++ )
   {
     if ( vResps['field'][i] == '' && vResps['val'][i] == '' )
     {
       continue
     }
     j++
     $('#http_add_response').click()
     $('tr[data-index="'+(j)+'"] select[name="http_response_event[]"]').val(vResps['event'][i])
     $('tr[data-index="'+(j)+'"] select[name="http_response_field[]"]').val(vResps['field'][i])
     $('tr[data-index="'+(j)+'"] input[name="http_response_inst[]"]').val(vResps['inst'][i])
     $('tr[data-index="'+(j)+'"] input[name="http_response_name[]"]').val(vResps['name'][i])
     $('tr[data-index="'+(j)+'"] select[name="http_response_type[]"]').val(vResps['type'][i])
     $('tr[data-index="'+(j)+'"] input[name="http_response_val[]"]').val(vResps['val'][i])
   }
   $('select[name="http_response_type[]"]').change()
<?php
}
elseif ( $connConfig['type'] == 'wsdl' )
{
	$params = [ 'name' => $connData['param_name'] ?? '', 'type' => $connData['param_type'] ?? '',
	            'val' => $connData['param_val'] ?? '', 'event' => $connData['param_event'] ?? '',
	            'field' => $connData['param_field'] ?? '', 'inst' => $connData['param_inst'] ?? '',
	            'func' => $connData['param_func'] ?? '', 'args' => $connData['param_func_args'] ?? '' ];
?>
   var vParams = JSON.parse( '<?php echo addslashes( json_encode( $params ) ); ?>' )
   for ( var i = 0, j = 0; i < vParams['name'].length; i++ )
   {
     if ( vParams['name'][i] == '' )
     {
       continue
     }
     j++
     $('#wsdl_add_param').click()
     $('tr[data-index="'+(j)+'"] input[name="wsdl_param_name[]"]').val(vParams['name'][i])
     $('tr[data-index="'+(j)+'"] select[name="wsdl_param_type[]"]').val(vParams['type'][i])
     $('tr[data-index="'+(j)+'"] input[name="wsdl_param_val[]"]').val(vParams['val'][i])
     $('tr[data-index="'+(j)+'"] select[name="wsdl_param_event[]"]').val(vParams['event'][i])
     $('tr[data-index="'+(j)+'"] select[name="wsdl_param_field[]"]').val(vParams['field'][i])
     $('tr[data-index="'+(j)+'"] input[name="wsdl_param_inst[]"]').val(vParams['inst'][i])
     $('tr[data-index="'+(j)+'"] select[name="wsdl_param_func[]"]').val(vParams['func'][i])
     $('tr[data-index="'+(j)+'"] input[name="wsdl_param_func_args[]"]').val(vParams['args'][i])
   }
   $('select[name="wsdl_param_type[]"]').change()
<?php
	$resps = [ 'event' => $connData['response_event'] ?? '', 'field' => $connData['response_field'] ?? '',
	           'inst' => $connData['response_inst'] ?? '', 'name' => $connData['response_name'] ?? '',
	           'type' => $connData['response_type'] ?? '', 'val' => $connData['response_val'] ?? '' ];
?>
   var vResps = JSON.parse( '<?php echo addslashes( json_encode( $resps ) ); ?>' )
   for ( var i = 0, j = 0; i < vResps['field'].length; i++ )
   {
     if ( vResps['field'][i] == '' )
     {
       continue
     }
     j++
     $('#wsdl_add_response').click()
     $('tr[data-index="'+(j)+'"] select[name="wsdl_response_event[]"]').val(vResps['event'][i])
     $('tr[data-index="'+(j)+'"] select[name="wsdl_response_field[]"]').val(vResps['field'][i])
     $('tr[data-index="'+(j)+'"] input[name="wsdl_response_inst[]"]').val(vResps['inst'][i])
     $('tr[data-index="'+(j)+'"] input[name="wsdl_response_name[]"]').val(vResps['name'][i])
     $('tr[data-index="'+(j)+'"] select[name="wsdl_response_type[]"]').val(vResps['type'][i])
     $('tr[data-index="'+(j)+'"] input[name="wsdl_response_val[]"]').val(vResps['val'][i])
   }
   $('select[name="wsdl_response_type[]"]').change()
<?php
}
?>
 })
</script>
<?php

// Display the project footer
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
