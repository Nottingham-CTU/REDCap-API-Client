<?php
/**
 *	API client connections list.
 */



// Check user can edit API client connections.
if ( ! $module->canEditConnections() )
{
	exit;
}



// Get the list of connections.
$listConnections = $module->getConnectionList();



// Display the project header
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
$module->writeStyle();

?>
<div class="projhdr">
 API Client &#8212; Connections
</div>
<p>&nbsp;</p>
<p>
 <button class="btn btn-sm btn-success" type="button" onclick="window.location='<?php
echo $module->getUrl('edit_connection.php'); ?>'">
  <i class="fas fa-plus"></i> Add Connection
 </button>
</p>
<?php
if ( count( $listConnections ) > 0 )
{
?>
<p>&nbsp;</p>
<table class="mod-apiclient-listtable" style="width:97%">
 <tr>
  <th colspan="2" style="font-size:130%">Connections</th>
 </tr>
<?php
	foreach ( $listConnections as $connID => $infoConnection )
	{
?>
 <tr>
  <td style="text-align:left">
   <span style="font-size:115%">
    <?php echo htmlspecialchars( $infoConnection['label'] ), "\n"; ?>
   </span>
   <br>
<?php /* TODO: Key details from connection. */ ?>
   <span style="font-size:90%">
    <b>Type:</b> <?php echo $infoReport['type']; ?> &nbsp;|&nbsp;
    <b>Category:</b> <?php echo $infoReport['category'] ?? '<i>(none)</i>'; ?> &nbsp;|&nbsp;
    <b>Visibility:</b> <?php echo $infoReport['visible'] ? 'visible' : 'hidden', "\n"; ?>
   </span>
  </td>
  <td style="width:90px;text-align:center">
   <a href="<?php echo $module->getUrl( 'edit_connection.php?conn_id=' . $connID );
?>" class="fas fa-pencil-alt fs12"> Edit</a>
  </td>
 </tr>
<?php
	}
?>
</table>
<?php
}

// Display the project footer
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
