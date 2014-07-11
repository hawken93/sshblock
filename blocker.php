<?php
// lol, quick visit, right?
// // Right, I was just about to say, I've managed to work out how it's done
// // quite quickly xD

//$file="/var/log/auth.log";
$path = "/opt/sshblock";
$file="$path/logpipe";
$db = "$path/block.db";



$ipt = new IPTables("BLOCKS","BLOCKS");

//$data = $ipt->listflush4();

//$t = new Tail($file);
$r = new Read($file);
// Block's callback is IPTables
$b = new Block($db,5,$ipt); // 5 attempts = block


$data4 = $ipt->listflush4();
$b->startup($data4);

while(1){
	
	$r->open();
	while (($line=$r->getline())!==false) {
		echo "<<: $line\n";
		$b->insert($line);
	}
	$r->close();
	$ipt->logger("Lost log pipe, reopening..");
	sleep(1);
}

class Read {
	var $sock;
	var $file;
	function __construct($file){
		$this->file=$file;
	}
	function open(){
		$this->sock = fopen($this->file,"r") or die("D'aww :(\n");
	}
	function close(){
		if($this->sock) fclose($this->sock);
		$this->sock = false;
	}
	function getline(){
		$data = fgets($this->sock, 65536);
		if($data===false) return false;
		return trim($data, "\r\n");
	}
}

class IPTables{
	var $chain4;
	var $chain6;

	var $block4;
	function __construct($chain4, $chain6){
		$this->chain4 = escapeshellarg($chain4);
		$this->chain6 = escapeshellarg($chain6);
	}
	function listflush4($syslog=true){
		if($syslog) $this->logger("Taking control of iptables (v4 chain: ".$this->chain4.")");
		// get IPs from iptables, add them to our list and rewrite them
		$data = `iptables -L {$this->chain4} -n | grep DROP | sed 's/  */ /g' | cut -d ' ' -f 4`;
		if(empty(trim($data))) return;
		$data = explode("\n",trim($data));
		$ips = array();
		foreach($data as $d) $ips[$d]=true;
		ksort($ips);
		`iptables -F {$this->chain4}`;
		return $ips;
	}
	/*function list4(){
		// get IPs from iptables, add them to our list and rewrite them
		// beware of the sed..
		$data = `iptables -L {$this->chain4} -n | sed 's/  *'/' /g' | cut -d ' ' -f 4 | tail -n+3`;
		if(empty(trim($data))) return;
		$data = explode("\n",trim($data));
		$ips = array();
		foreach($data as $d) $ips[$d]=true;
		ksort($ips);
		`iptables -F {$this->chain4}`;
		foreach($ips as $ip=>$v) $this->blacklist4($ip, false);
	}*/
	function logger($mesg){
		echo "$mesg\n";
		$m = escapeshellarg($mesg);
		`logger -t sshblock -- $m`;
	}
	function whitelist4($ip){
		if(isset($this->block4[$ip])){
			$ip = escapeshellarg($ip);
			$this->logger("Unblocking $ip");
			$cmd="iptables -D ".$this->chain4." -s $ip -j DROP\n";
			`$cmd 2>/dev/null`;
			unset($this->block4[$ip]);
		}
	}
	function whitelist6($ip){
		$ip = escapeshellarg($ip);
		$this->logger("Unblocking $ip");
		$cmd="ip6tables -D ".$this->chain6." -s $ip -j DROP\n";
		`$cmd 2>/dev/null`;
	}
	function blacklist4($ip, $syslog=true){
		if(!isset($this->block4[$ip])){
			$this->block4[$ip]=true;
			$ip = escapeshellarg($ip);
			if($syslog) $this->logger("Blocking $ip");
			$cmd="iptables -A ".$this->chain4." -s $ip -j DROP\n";
			echo `$cmd`;
		}
	}
	function blacklist6($ip){
		$ip = escapeshellarg($ip);
		$this->logger("Blocking $ip");
		$cmd="ip6tables -A ".$this->chain6." -s $ip -j DROP\n";
		`$cmd`;
	}
}

class Block{
	var $file;
	var $db;
	var $state4;
	var $state6;
	var $thr;
	// cb stands for callback
	var $cb;
	function __construct($db,$threshold,$callback){
		$this->file =$db;
		$this->thr = $threshold;
		$this->cb = $callback;
		$this->read();
	}
	function read(){
		$this->db = unserialize(file_get_contents($this->file));
	}
	function write(){
		file_put_contents($this->file,serialize($this->db));
	}
	function startup($data4){ // get in sync
		$this->cb->logger("sshblock starting up");
		foreach($this->db['blacklist4'] as $ip=>$banned){
			if($banned){
				$this->cb->blacklist4($ip, false);
				$this->db['blacklist4'][$ip]=true;
			} else{
				unset($this->db['blacklist4'][$ip]);
			}
		}
		foreach($data4 as $ip=>$banned){
			if($banned){
				$this->cb->blacklist4($ip, false);
				$this->db['blacklist4'][$ip]=true;
			}
		}
		$this->write();
	}


	function whitelist4($ip){
		if(!isset($this->db['whitelist4'][$ip])){
			$this->db['whitelist4'][$ip]=true;
			$this->cb->whitelist4($ip);
		}
		$this->write();
	}
	function blacklist4($ip, $log=true){
		if(isset($this->db['whitelist4'][$ip]) && $this->db['whitelist4'][$ip]){
			return; // Cannot blacklist anything whitelisted
		}
		if(!isset($this->db['blacklist4'][$ip])){
			$this->db['blacklist4'][$ip]=true;
			$this->cb->blacklist4($ip, $log);
		}
		$this->write();
	}
	function whitelist6($ip){
		if(isset($this->db['whitelist6'][$ip]) && $this->db['whitelist6'][$ip]){
			return; // Cannot blacklist anything whitelisted
		}
		if(!isset($this->db['whitelist6'][$ip])){
			$this->db['whitelist6'][$ip]=true;
			$this->cb->whitelist6($ip);
		}
		$this->write();
	}
	function blacklist6($ip){
		if(!isset($this->db['blacklist6'][$ip])){
			$this->db['blacklist6'][$ip]=true;
			$this->cb->blacklist6($ip);
		}
		$this->write();
	}



	function success4($ip){
		if(isset($this->db['blacklist4'][$ip])){
			echo "Warning, whitelisting a previously blacklisted ip: $ip\n";
			unset($this->db['blacklist4'][$ip]);
		}
		if(isset($this->state4[$ip])){
			unset($this->state4[$ip]);
		}
		$this->whitelist4($ip);
	}
	function success6($ip){
		if(isset($this->db['blacklist6'][$ip])){
			echo "Warning, whitelisting a previously blacklisted ip: $ip\n";
			unset($this->db['blacklist6'][$ip]);
		}
		if($this->state6[$ip]>0){
			unset($this->state6[$ip]);
		}
		$this->whitelist6($ip);
	}
	function failure4($ip){
		if(isset($this->db['whitelist4'][$ip])) return;

		if(!isset($this->state4[$ip])) $this->state4[$ip]=0;
		$this->state4[$ip]++;
		if($this->state4[$ip]>=$this->thr){
			$this->blacklist4($ip);
		}
	}
	function failure6($ip){
		if(isset($this->db['whitelist6'][$ip])) return;

		if(!isset($this->state6[$ip])) $this->state6[$ip]=0;
		$this->state6[$ip]++;
		if($this->state6[$ip]>=$this->thr){
			$this->blacklist6($ip);
		}
	}

	function insert($l){
		$l = trim($l);
		if(preg_match('/^([a-zA-Z]+\s+[0-9]+\s+[0-9:]+) ([^ ]+) sshd\[[0-9]+\]: (.*)$/', $l, $m)){
			$l = $m[3];
		} else if(preg_match('/^([0-9-T:\.\+]+) ([^ ]+) sshd\[[0-9]+\]: (.*)$/', $l, $m)) {
			$l = $m[3];
		} else {
			echo "Not sshd relevant afaik\n";
			return;
		}

		$l = $m[3];
		if(preg_match('/^Failed password for( invalid user)? ([^ ]+) from ([0-9\.]+) port ([0-9]+) ssh[12]$/', $l, $m)){
			array_shift($m);
			array_shift($m);
			echo "failure4\n";
			$this->failure4($m[1]);
			return;
		} else if(preg_match('/^Failed password for( invalid user)? ([^ ]+) from ([0-9a-f:]+) port ([0-9]+) ssh[12]$/', $l, $m)){
			array_shift($m);
			array_shift($m);
			echo "failure6\n";
			$this->failure6($m[1]);
			return;
		} else if(preg_match('/^Accepted password for ([^ ]+) from ([0-9a-f\.]+) port ([0-9]+) ssh[12]$/', $l, $m)){
			array_shift($m);
			echo "accept4\n";
			$this->success4($m[1]);
			return;
		} else if(preg_match('/^Accepted password for ([^ ]+) from ([0-9a-f:]+) port ([0-9]+) ssh[12]$/', $l, $m)){
			array_shift($m);
			echo "accept6\n";
			$this->success6($m[1]);
			return;
		}


		if(preg_match('/^(pam|PAM)/', $l)) return;
		if(preg_match('/^Received/', $l)) return;
		if(preg_match('/^Disconnecting/', $l)) return;
		if(preg_match('/^Connection closed by/', $l)) return;
		echo $l."\n"; // log unknown lines
	}
}

/** THANKS pnp.net!!!
 * Tail a file (UNIX only!)
 * Watch a file for changes using inotify and return the changed data
 *
 * @param string $file - filename of the file to be watched
 * @param integer $pos - actual position in the file
 * @return string
 */
class Tail {
	var $file;
	var $pos;
	var $fh;
	function __construct($file){
		$this->file = $file;
		$this->lastpos = 0;
		$this->fh = false;
	}
	// inotify:
	//  - install php-pear
	//  - install php5-dev
	//  - pecl install inotify
	//  - go to the failed bin
	//  - unpack
	//  - phpize
	//  - configure
	//  - make, make test, make install
	//  - php5.ini, extension=inotify.so
	function getline(){
		if(!$this->fh) $this->fh = fopen($this->file,'r');
		if(feof($this->fh)) return false;
		$line=fgets($this->fh);
		return $line;
	}
	function tail() {
		//get the size of the file
		if(!$this->pos) $this->pos = filesize($this->file);
		// Open an inotify instance
		$fd = inotify_init();
		// Watch $file for changes.
		$watch_descriptor = inotify_add_watch($fd, $this->file, IN_ALL_EVENTS);
		// Loop forever (breaks are below)
		while (true) {
			// Read events (inotify_read is blocking!)
			$events = inotify_read($fd);
			// Loop though the events which occured
			foreach ($events as $event=>$evdetails) {
				// React on the event type
				switch (true) {
					// File was modified
				case ($evdetails['mask'] & IN_MODIFY):
					$buf="";
					// Stop watching $file for changes
					inotify_rm_watch($fd, $watch_descriptor);
					// Close the inotify instance
					fclose($fd);
					// open the file
					$fp = fopen($this->file,'r');
					if (!$fp) return false;
					// seek to the last EOF position
					fseek($fp,$this->pos);
					// read until EOF
					while (!feof($fp)) {
						$buf .= fread($fp,8192);
					}
					// save the new EOF to $pos
					$this->pos = ftell($fp); // (remember: $pos is called by reference)
					// close the file pointer
					fclose($fp);
					// return the new data and leave the function
					return $buf;
					// be a nice guy and program good code ;-)
					break;

					// File was moved or deleted
				case ($evdetails['mask'] & IN_MOVE):
				case ($evdetails['mask'] & IN_MOVE_SELF):
				case ($evdetails['mask'] & IN_DELETE):
				case ($evdetails['mask'] & IN_DELETE_SELF):
					// Stop watching $file for changes
					inotify_rm_watch($fd, $watch_descriptor);
					// Close the inotify instance
					fclose($fd);
					// Return a failure
					return false;
					break;
				}
			}
		}
	}
};