<?php
//Script for use with SOAP RPC format

error_reporting(E_ALL);
ini_set('display_errors', True);

// START INPUT VARIABLES
$ipNew = ip2long($ip);
$min = 'x.x.x.x'; //min host for subnet
$min_ip = ip2long($min);
$max = 'x.x.x.x'; //max host for subnet
$max_ip = ip2long($max);
$count = '0';
// END INPUT VARIABLES

// START OF AUTHENTICATION TO CUCM
require('cucmAuth.php');

$client = new SoapClient("AXLAPI.wsdl",
array('trace'=>true,
'exceptions'=>true,
'location'=>"https://$host:8443/axl",
'login'=>$username,
'password'=>$passwd,
));
// END OF AUTHENTICATION TO CUCM

// START QUERY CUCM DB FOR DEVICE POOL INFORMATION
$sql1 = "select * from devicepool where devicepool.name = 'addNameOfDevicePool'"; // Replace 'addNameOfDevicePool' with name of the device pool your querying.

$devicePoolInformation = $client->executeSQLQuery(array("sql"=>$sql1));
// START QUERY CUCM DB FOR DEVICE POOL INFORMATION

$devicePoolName = $devicePoolInformation->return->row->name;
$devicePoolPKID = $devicePoolInformation->return->row->pkid;

echo "<b><font size='5'>Device Pool Name:</font></b><font size='5'> " . $devicePoolName . "</font><br>";
//echo "<b>Device Pool PKID:</b> " . $devicePoolPKID . "<br>";

// START QUERY CUCM DB FOR DEVICE POOL MEMBERS
$sql2 = "select * from device where fkdevicepool='$devicePoolPKID'";
$devicePoolMembers = $client->executeSQLQuery(array("sql"=>$sql2));

// START CACHEING PAGE
ob_start();

//THIS IS RUN WHEN MORE THAN ONE PHONE IS FOUND IN THE DEVICE POOL
foreach($devicePoolMembers->return->row as $first)
{
  $count++;
  $soapClient = new SoapClient("https://hostname:8443/realtimeservice/services/RisPort?wsdl", array('stream_context' => $context, 'trace'=>true, 'login' => $username,'password'=> $passwd));
  $deviceIP = $soapClient->SelectCmDevice("", array('SelectBy'=>'Name','Status'=>'Registered','SelectItems'=>array('SelectItem[0]'=>array('Item'=>"$first->name"))));
  foreach($deviceIP as $first)
  {
    if( is_array($first->CmNodes) )
    {
      $CmNodes = $first->CmNodes;
      foreach($CmNodes as $second)
      {
        if( is_array($second->CmDevices) )
        {
          $CmDevices = $second->CmDevices;
          foreach($CmDevices as $dev)
          {
            $alertDevicePool = null;
            $alertDeviceCSS = null;
            $alertExtensionCSS = null;
            $alertExtensionsCSS = array();

            $client = new SoapClient("AXLAPI.wsdl",array('trace'=>true,'exceptions'=>true,'location'=>"https://$host:8443/axl",'login'=>$username,'password'=>$passwd,));
            $sql3 = "select * from typemodel where enum='$dev->Model'";
            $deviceModel = $client->executeSQLQuery(array("sql"=>$sql3)); //Finds model type of device
            $deviceModelName = $deviceModel->return->row->name;

            // This section was added to remove Cisco IP Communicator from the subnet device check.  It also decrements the device from the total device count
            if($deviceModelName == 'Cisco IP Communicator')
            {
              $count--;
              continue;
            }

            $sql4 = "select * from device where device.Name = '$dev->Name'"; //PKID of device
            $deviceInfo = $client->executeSQLQuery(array("sql"=>$sql4));
            $devicePkid = $deviceInfo->return->row->pkid;
            $deviceCallingSearchSpacePKID = $deviceInfo->return->row->fkcallingsearchspace;

            $sql5 = "select * from callingsearchspace where callingsearchspace.pkid = '$deviceCallingSearchSpacePKID'"; //PKID of device calling searchspace
            $deviceCallingSearchSpace = $client->executeSQLQuery(array("sql"=>$sql5));
            $deviceCallingSearchSpaceName = $deviceCallingSearchSpace->return->row->name;

            $sql6 = "select numplan.dnorpattern, numplan.pkid from device, devicenumplanmap, numplan where (device.pkid = '$devicePkid') and (device.pkid = devicenumplanmap.fkdevice) and (devicenumplanmap.fknumplan = numplan.pkid)"; //Extensions of device

            $deviceExtensionsQuery = $client->executeSQLQuery(array("sql"=>$sql6));
            echo "<b>Device Name</b><br>" . $dev->Name . "<br>";
            //echo "<b>Device PKID: </b><br>" . $devicePkid . "<br>";
            echo "<b>Device Model</b><br>" . $deviceModel->return->row->name . "<br>";
            //echo "<b>Device Model Name</b><br>" . $deviceModelName . "<br>";
            echo "<b>Description</b><br>" . $dev->Description . "<br>";
            echo "<b>Phone IP Address</b><br>" . $dev->IpAddress . "<br>";


            $ip = $dev->IpAddress; //Prepare $ip variable for device pool/subnet check
            $ip = ip2long($ip);
            if (($ip <= $max_ip && $min_ip <= $ip) || ($deviceModelName == 'Cisco IP Communicator'))
            {
              echo "<b>Device Pool Status:</b><br> Correct<br>";
            }
            else
            {
              echo "<b>Device Pool Status:</b><font color=red><br> Incorrect</font><br>";
              $alertDevicePool = "Incorrect Device Pool for phone $dev->Name";
            }
            echo "<b>Device Calling Search Space Name: </b><br>" . $deviceCallingSearchSpaceName . "<br>";
            $devicePoolNameString = substr($devicePoolName, 2);
            if (strpos($deviceCallingSearchSpaceName, $devicePoolNameString) !== FALSE)
            {
              echo "<b>Device Calling Search Space Status:</b><br> Correct<br>";
            }
            else
            {
              echo "<b>Device Calling Search Space Status:</b><font color=red><br> Incorrect</font><br>";
              $alertDeviceCSS = "Device Calling Search Space is incorrect for $dev->Name (" . $deviceCallingSearchSpaceName . ")";
            }

            if(is_array($deviceExtensionsQuery->return->row)) // Checks to see if device has more than one extension.  In either case, print out the calling search space assigned to the extension, if any
            {
              foreach($deviceExtensionsQuery->return->row as $deviceExtensions)
              {
                $sql7 = "select numplan.fkcallingsearchspace_sharedlineappear, numplan.pkid, callingsearchspace.name from numplan, callingsearchspace where (numplan.pkid = '$deviceExtensions->pkid') and (numplan.fkcallingsearchspace_sharedlineappear = callingsearchspace.pkid)";
                $extensionsCallingSearchSpaceQuery = $client->executeSQLQuery(array("sql"=>$sql7));
                echo "&nbsp;&nbsp;<b>Extension</b><br>&nbsp;&nbsp;" . $deviceExtensions->dnorpattern . "<br>";
                $deviceExtensionsString = $deviceExtensions->dnorpattern;

                $extensionCallingSearchSpaceName = $extensionsCallingSearchSpaceQuery->return->row->name;
                if($extensionCallingSearchSpaceName == null)
                {
                  echo "&nbsp;&nbsp;<b>Extension Calling Search Space Name:</b><br>&nbsp;&nbsp;-<br>";
                }
                else
                {
                  echo "&nbsp;&nbsp;<b>Extension Calling Search Space Name:</b><br>&nbsp;&nbsp" . $extensionCallingSearchSpaceName . "<br>";
                  $devicePoolNameString = substr($devicePoolName, 2);
                  if (strpos($extensionCallingSearchSpaceName, $devicePoolNameString) !== FALSE)
                  {
                    echo "&nbsp;&nbsp;<b>Extension Calling Search Space Status:</b> Correct<br>";
                  }
                  else
                  {
                    echo "&nbsp;&nbsp;<b>Extension Calling Search Space Status:</b><font color=red> Incorrect</font><br>";
                    array_push($alertExtensionsCSS, "$deviceExtensionsString=>$extensionCallingSearchSpaceName");
                  }
                }
              }
              //START SUMMARY OF ERRORS FOR MULTILINE PHONE
              if($alertDevicePool !== null)
              {
                echo "<br>" . $alertDevicePool . "<br>";
              }
              if($alertDeviceCSS !== null)
              {
                echo $alertDeviceCSS . "<br>";
              }
              if(!empty($alertExtensionsCSS))
              {
                $alertExtensionsCSSImploded = implode("\r\n",$alertExtensionsCSS);
                $alertExtensionsCSSImplodedDesc = "The following extension(s) have incorrectly configured CSSs: \r\n" . $alertExtensionsCSSImploded;
                foreach($alertExtensionsCSS as $ext => $ext_value)
                {
                  echo "Extension Calling Search Space is incorrect for " . $ext_value;
                  echo "<br>";
                }
              }
              echo "<hr>";
              if(isset($alertDevicePool))
              {
                $to = "email@address.com";
                $subject = "Telecom Alert - Incorrectly configured phone $dev->Name, please correct.";
                $txt = $alertDevicePool . "\r\n\r\n" . $alertDeviceCSS . "\r\n\r\n" . $alertExtensionsCSSImplodedDesc;


                $headers = "From: email@address.com";
                mail($to,$subject,$txt,$headers);
              }
              else
              {
                continue;
              }
            }
            //END SUMMARY OF ERRORS FOR MULTILINE PHONE
            else // Device has only one extension
            {
              $deviceExtensionsPKID = $deviceExtensionsQuery->return->row->pkid;
              $sql7 = "select numplan.fkcallingsearchspace_sharedlineappear, numplan.pkid, callingsearchspace.name from numplan, callingsearchspace where (numplan.pkid = '$deviceExtensionsPKID') and (numplan.fkcallingsearchspace_sharedlineappear = callingsearchspace.pkid)";
              $extensionsCallingSearchSpaceQuery = $client->executeSQLQuery(array("sql"=>$sql7));
              $deviceExtension = $deviceExtensionsQuery->return->row->dnorpattern;
              echo "&nbsp;&nbsp;<b>Extension</b><br>&nbsp;&nbsp;" . $deviceExtension . "<br>";
              $extensionCallingSearchSpaceName = $extensionsCallingSearchSpaceQuery->return->row->name;
              if($extensionCallingSearchSpaceName == null)
              {
                echo "&nbsp;&nbsp;<b>Extension Calling Search Space Name:</b><br>&nbsp;&nbsp;-<br>";
              }
              else
              {
                echo "&nbsp;&nbsp;<b>Extension Calling Search Space Name:</b><br>&nbsp;&nbsp" . $extensionCallingSearchSpaceName . "<br>";
                $extensionCallingSearchSpaceString = substr($extensionsCallingSearchSpaceName, 2);
                if (strpos($extensionCallingSearchSpaceName, $devicePoolNameString) !== FALSE)
                {
                  echo "&nbsp;&nbsp;<b>Extension Calling Search Space Status:</b> Correct<br>";
                }
                else
                {
                  echo "&nbsp;&nbsp;<b>Extension Calling Search Space Status:</b><font color=red> Incorrect</font><br>";
                  $alertExtensionCSS = "Extension Calling Search Space is incorrect for $deviceExtension (" . $deviceCallingSearchSpaceName . ")";
                }
              }
              //START SUMMARY OF ERRORS FOR LINE PHONE
              if($alertDevicePool !== null)
              {
                echo "<br>" . $alertDevicePool . "<br>";
              }
              if($alertDeviceCSS !== null)
              {
                echo $alertDeviceCSS . "<br>";
              }
              if($alertExtensionCSS !== null)
              {
                echo $alertExtensionCSS;
              }
              echo "<hr>";
              if(isset($alertDevicePool))
              {
                $to = "email@address.com";
                $subject = "Telecom Alert - Incorrectly configured phone $dev->Name, please correct.";
                $txt = $alertDevicePool . "\r\n\r\n" . $alertDeviceCSS . "\r\n\r\n" . $alertExtensionCSS . "\r\n\r\n";
                $headers = "From: email@address.com";
                mail($to,$subject,$txt,$headers);
              }
              else
              {
                continue;
              }
              //END SUMMARY OF ERRORS FOR SINGLE LINE PHONE
            }
          }
        }
      }
    }
  }

}
$search_results = ob_get_clean();
echo "<font size='5'><b>Total Devices Registered:</b> " . $count . "</font><br><br>";
echo $search_results;
// END PRINT DEVICE POOL MEMBER INFORMATION
?>
