<?php
/**
 *	API client connections list.
 */



// Check user can edit API client connections.
if ( ! $module->canEditConnections() )
{
	exit;
}



// Handle AJAX requests.
if ( $_SERVER['REQUEST_METHOD'] == 'POST' )
{
	header( 'Content-Type: text/plain; charset=utf-8' );
	if ( isset( $_SESSION['module_apiclient_debug'] ) &&
	     isset( $_SESSION['module_apiclient_debug']['data'] ) )
	{
		echo $_SESSION['module_apiclient_debug']['data'], "\n\n\n";
	}
	$_SESSION['module_apiclient_debug'] = [ 'ts' => time() ];
	exit;
}



// Display the project header
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
$module->writeStyle();

?>
<div class="projhdr">
 API Client &#8212; Test/Debug
</div>
<p>&nbsp;</p>
<p>To test/debug your API Client connection, keep this page open while you trigger your connection.</p>
<p>Please note that this will only work for connections triggered by form submission.</p>
<p>&nbsp;</p>
<div id="apidebug" style="width:99%;height:500px;overflow-x:auto;overflow-y:scroll;background:#ccc;padding:3px;font-family:monospace;white-space:pre"></div>
<script type="text/javascript">
  $(function()
  {
    var vFuncResult = function()
    {
      $.post( '<?php echo addslashes( $_SERVER['REQUEST_URI'] ); ?>', {},
              function( data )
              {
                $('#apidebug').text( $('#apidebug').text() + data )
                setTimeout( vFuncResult, 10000 )
              }, 'text' )
    }
    vFuncResult()
  })
</script>
<?php

// Display the project footer
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
