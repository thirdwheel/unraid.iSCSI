<?php
/* Copyright 2020, Simon Fairweather
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$plugin = "unassigned.devices";
$docroot = $docroot ?: @$_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$disks = @parse_ini_file("$docroot/state/disks.ini", true);
 $VERBOSE=FALSE; 
#########################################################
############         DISK FUNCTIONS         #############
#########################################################

function get_unassigned_disks() {
	global $disks;

	$ud_disks = $paths = $unraid_disks = $b =  array();
	/* Get all devices by id. */
	 exec('lsblk -OJ'  ,$tj) ;
	$t=json_decode(implode("", $tj), true);
	$t = $t['blockdevices'] ;	
	foreach (listDir("/dev/disk/by-id/") as $p) {
		$r = realpath($p);
		/* Only /dev/sd*, dev/sr0, /dev/hd*, and /dev/nvme* devices. */
		if (! is_bool(strpos($r, "/dev/sd")) || !is_bool(strpos($r, "/dev/hd")) || !is_bool(strpos($r, "/dev/nvme")) || !is_bool(strpos($r, "/dev/sr")) ) {
			$paths[$r] = $p;
			}
		}		
	natsort($paths);
	
	/* Get all unraid disk devices (array disks, cache, and pool devices) */
	foreach ($disks as $d) {
		if ($d['device']) {
			$unraid_disks[] = "/dev/".$d['device'];
		}
	}
	
	foreach($t as $tr) {
		    
			if ($tr['tran'] != '' ) {   
			$b["/dev/".$tr['name']]=$tr;
		}}
	
	
	$LIOdevices=build_iscsi_devices(get_iscsi_json()) ;

	/* Create the array of unassigned devices. */
	foreach ($paths as $path => $d) {
		if (($d != "")  && (preg_match("#^(.(?!part))*$#", $d))) {
			if (in_array($path, $unraid_disks)) $unraid=true ; else $unraid=false ;
				$m=$b[$path]['children'] ;
				if ($m==null && $b[$path]['type']=="rom" && $b[$path]['fstype']!='') $m=array($b[$path]) ;
           
				if (array_search($d , array_column($LIOdevices, 'dev')) !==false || array_search($path , array_column($LIOdevices, 'dev')) !==false) $defined = true ; else $defined=false; 

				$ud_disks[$path] = array(
									"device"=>$d,  
									"unraid"=>$unraid, 
									"hctl"=>$b[$path]['hctl'] ,
									"type"=>$b[$path]['type'] ,
									"vendor"=>$b[$path]['vendor'] ,
									"model"=>$b[$path]['model'] ,
									"rev"=>$b[$path]['rev'] ,
									"serial"=>$b[$path]['serial'] ,
									"tran"=>$b[$path]['tran'],
									"size"=>$b[$path]['size'],
									"bpartitions"=>$m,
									"defined"=> $defined,
									"name"=>$b[$path]["name"]
		) ;

		}
	}
	ksort($ud_disks, SORT_NATURAL) ;
	return $ud_disks ;
}

function listDir($root) {
	$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, 
			RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD);
	$paths = array();
	foreach ($iter as $path => $fileinfo) {
		if (! $fileinfo->isDir()) $paths[] = $path;
	}
	return $paths;
}	

function unassigned_log($m, $type = "NOTICE") {
	global $plugin;

	if ($type == "DEBUG" && ! $GLOBALS["VERBOSE"]) return NULL;
	$m		= print_r($m,true);
	$m		= str_replace("\n", " ", $m);
	$m		= str_replace('"', "'", $m);
	$cmd	= "/usr/bin/logger ".'"'.$m.'"'." -t".$plugin;
	exec($cmd);
}

function get_iscsi_json() {
	global $iSCSI_JSON ;


	$string = file_get_contents("/etc/target/saveconfig.json");
	$tj = json_decode($string, true);
	$t=$iSCSI_JSON=json_decode(implode("", $tj), true);
	
	return $tj ;
}

function build_iscsi_devices($tj) {
	global $iSCSI_Storage ;

	$dev=0 ;
	$sd = $iSCSI_Storage= $tj["storage_objects"] ;
	foreach ($sd as $key=>$sr) {
		unset($sr["alua_tpgs"]) ;
		unset($sr["attributes"]) ;
		$sd[$key] = $sr;	
	}
	
		
	
	return $sd ;
}    

function build_fileio($tj) {
	 
	$dev=0 ;
	$sd =  $tj["storage_objects"] ;
	foreach ($sd as $key=>$sr) {
		unset($sr["alua_tpgs"]) ;
		unset($sr["attributes"]) ;
		$sd[$key] = $sr;	
	}
	
		
	
	return $sd ;
}    

function build_iscsi_initiators($tj) {
	global $targetname ;
	global $luns ;

	$dev=0 ;
	
	$sd = $tj["targets"][0] ;
	$tgt=$sd["tpgs"][0] ;
	$luns=$tgt["luns"] ;
	$node_acls=$tgt["node_acls"] ;
	$portals=$tgt["portals"] ;
	$parms=$tgt["parameters"] ;
	$enable=$tgt["enable"] ;
	$targetname=$sd["wwn"] ;
	
		return $node_acls ;
}    

function filelock() {
	// file_exists (string $filename ) : bool
	$fp = fopen('/var/run/targetcli.lock', 'w');
    if (!flock($fp, LOCK_EX|LOCK_NB, $wouldblock)) {
		if ($wouldblock) {
			// another process holds the lock
			fclose($fp) ;
			if (file_exists("/var/run/iscsi.tab")) 	unlink("/var/run/iscsi.tab") ;
			return false ;
		}
	}
	else {
		fclose($fp) ;
		
		$fp = fopen('/var/run/iscsi.tab', 'w');
		fwrite($fp, '1');
		fclose($fp) ;
		return true ;
	}
} 

function processTargetcli($cmdstr) {
	# Write command string a process
	# targetctl  /tmp/string > /var/run/targetcli.last
	
}