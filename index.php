<?php
// K-ID Backend - Patient + Organisation + Provider - Single File PHP API
// Implements Patient Guide (22 pages) + Organisation Guide (15 pages) sharing same DB
// Beta v1.0 LAUTECH 2026 | XAMPP + Docker + Render

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=utf-8");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function loadEnv($path){ if(!file_exists($path)) return; foreach(file($path, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l){ $t=trim($l); if(!$t||$t[0]=='#'||!strpos($l,'=')) continue; list($k,$v)=explode('=',$l,2); $k=trim($k); $v=trim(trim($v),"\"'"); $_ENV[$k]=$v; putenv("$k=$v"); } }
loadEnv(__DIR__.'/.env');
$DB_HOST=$_ENV['DB_HOST']??getenv('DB_HOST')?:'127.0.0.1';
$DB_PORT=$_ENV['DB_PORT']??getenv('DB_PORT')?:'3306';
$DB_NAME=$_ENV['DB_NAME']??getenv('DB_NAME')?:'kid_db';
$DB_USER=$_ENV['DB_USER']??getenv('DB_USER')?:'root';
$DB_PASS=$_ENV['DB_PASS']??getenv('DB_PASS')?:'';
$JWT_SECRET=$_ENV['JWT_SECRET']??getenv('JWT_SECRET')?:'kid_beta_jwt_secret_change_in_prod_2026!_lautech';
$APP_ENV=$_ENV['APP_ENV']??'development';

try{
 $pdo=new PDO("mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}catch(Exception $e){ http_response_code(500); echo json_encode(["success"=>false,"message"=>"DB connection failed: ".$e->getMessage(),"error"=>"DB_ERROR"]); exit; }

function jsonResp($ok,$msg,$data=null,$err=null,$code=200){ http_response_code($code); $o=["success"=>$ok,"message"=>$msg]; if($data!==null) $o["data"]=$data; if($err) $o["error"]=$err; echo json_encode($o, JSON_UNESCAPED_SLASHES); exit; }
function inp(){ $r=file_get_contents('php://input'); $j=json_decode($r,true); return is_array($j)?$j:[]; }
function b64e($d){ return rtrim(strtr(base64_encode($d),'+/','-_'),'='); }
function b64d($d){ $r=strlen($d)%4; if($r) $d.=str_repeat('=',4-$r); return base64_decode(strtr($d,'-_','+/')); }
function signJwt($p,$s){ $h=b64e(json_encode(["alg"=>"HS256","typ"=>"JWT"])); $p["iat"]=time(); $p["exp"]=time()+60*60*24*7; $b=b64e(json_encode($p)); $sig=b64e(hash_hmac('sha256',"$h.$b",$s,true)); return "$h.$b.$sig"; }
function verifyJwt($t,$s){ $p=explode('.',$t); if(count($p)!==3) return null; list($hh,$bb,$ss)=$p; $calc=b64e(hash_hmac('sha256',"$hh.$bb",$s,true)); if(!hash_equals($calc,$ss)) return null; $d=json_decode(b64d($bb),true); if(!$d||($d["exp"]??0)<time()) return null; return $d; }
function bearer(){ $h=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''; if(preg_match('/Bearer\s+(.*)$/i',$h,$m)) return trim($m[1]); return null; }
function auth($role=null){ global $JWT_SECRET; $tok=bearer(); if(!$tok) jsonResp(false,"Authentication required",null,"UNAUTHORIZED",401); $d=verifyJwt($tok,$JWT_SECRET); if(!$d) jsonResp(false,"Invalid or expired token",null,"UNAUTHORIZED",401); if($role && ($d["role"]??'')!==$role) jsonResp(false,"Forbidden: wrong role",null,"FORBIDDEN",403); return $d; }
function getOrg($id){ global $pdo; $s=$pdo->prepare("SELECT * FROM organisations WHERE id=?"); $s->execute([$id]); return $s->fetch(); }
function getPatient($id){ global $pdo; $s=$pdo->prepare("SELECT * FROM patients WHERE id=?"); $s->execute([$id]); return $s->fetch(); }
function getPatientByKid($kid){ global $pdo; $s=$pdo->prepare("SELECT * FROM patients WHERE kid_number=?"); $s->execute([$kid]); return $s->fetch(); }
function getProvider($id){ global $pdo; $s=$pdo->prepare("SELECT * FROM providers WHERE id=?"); $s->execute([$id]); return $s->fetch(); }
function logActivity($pid,$action,$desc,$actor_id=null,$actor_type=null,$rec_id=null,$req_id=null,$status=null){
 global $pdo; try{ $pdo->prepare("INSERT INTO activity_log (patient_id, action, description, actor_id, actor_type, record_id, request_id, status) VALUES (?,?,?,?,?,?,?,?)")->execute([$pid,$action,$desc,$actor_id,$actor_type,$rec_id,$req_id,$status]); }catch(Exception $e){ error_log("activity_log fail: ".$e->getMessage()); }
}
function notifyPatient($pid,$type,$title,$msg,$ref_id=null,$ref_type=null){
 global $pdo; $pdo->prepare("INSERT INTO notifications (patient_id, type, title, message, reference_id, reference_type) VALUES (?,?,?,?,?,?)")->execute([$pid,$type,$title,$msg,$ref_id,$ref_type]);
}
function isValidCAC($c){ return preg_match('/^(CAC|RC)[\/\-]?[A-Z0-9\/\-]+$/i',trim($c)) && preg_match('/\d{3,}/',$c); }
function genKid(){ return 'KID-'.date('Y').str_pad(mt_rand(0,99999),5,'0',STR_PAD_LEFT).mt_rand(0,9); }
function genToken(){ return bin2hex(random_bytes(32)); }
function requireVerifiedOrg($a){ $o=getOrg($a["orgId"]); if(!$o) jsonResp(false,"Organisation not found",null,"NOT_FOUND",404); if(($o["verification_status"]??'')!=='verified') jsonResp(false,"Organisation is not yet verified. Contact Karevo support.",null,"ORG_NOT_VERIFIED",403); return $o; }
function requireVerifiedProvider($a){ $p=getProvider($a["providerId"]); if(!$p) jsonResp(false,"Provider not found",null,"NOT_FOUND",404); if(($p["verification_status"]??'')!=='verified') jsonResp(false,"Provider not yet verified",null,"PROVIDER_NOT_VERIFIED",403); return $p; }

$method=$_SERVER['REQUEST_METHOD'];
$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$uri=rtrim($uri,'/'); if($uri==='') $uri='/';
$pos=strpos($uri,'/api'); if($pos!==false) $uri=substr($uri,$pos);
$path=$uri;

// Health
if($path==='/'||$path==='/health'){ jsonResp(true,"K-ID API running", ["version"=>"v1.0 Patient+Org","env"=>$APP_ENV,"tables"=>["patients","providers","organisations","health_records","record_requests","qr_codes","activity_log","notifications","organisation_access"]]); }

// ==================== PROVIDER AUTH (needed for sending records) ====================
if($method==='POST' && $path==='/api/provider/register'){
 $in=inp(); foreach(["provider_name","license_number","email","password"] as $f) if(empty($in[$f])) jsonResp(false,"$f required",null,"VALIDATION_ERROR",422);
 global $pdo; $hash=password_hash($in["password"],PASSWORD_BCRYPT);
 try{ $pdo->prepare("INSERT INTO providers (provider_name, facility_type, license_number, email, phone, password_hash, verification_status) VALUES (?,?,?,?,?,?,'pending')")->execute([$in["provider_name"], $in["facility_type"]??'hospital', $in["license_number"], $in["email"], $in["phone"]??'08000000000', $hash]); }catch(Exception $e){ jsonResp(false,"License or email exists",null,"VALIDATION_ERROR",409); }
 jsonResp(true,"Provider registered, pending verification", ["id"=>$pdo->lastInsertId()], null,201);
}
if($method==='POST' && $path==='/api/provider/login'){
 $in=inp(); global $pdo; $s=$pdo->prepare("SELECT * FROM providers WHERE email=?"); $s->execute([$in["email"]??'']); $p=$s->fetch(); if(!$p||!password_verify($in["password"],$p["password_hash"])) jsonResp(false,"Invalid credentials",null,"UNAUTHORIZED",401);
 $tok=signJwt(["providerId"=>$p["id"],"role"=>"provider"], $JWT_SECRET); unset($p["password_hash"]); jsonResp(true,"Provider login", ["token"=>$tok,"provider"=>$p]);
}
if($method==='GET' && $path==='/api/provider/me'){ $a=auth('provider'); $p=getProvider($a["providerId"]); unset($p["password_hash"]); jsonResp(true,"Provider profile",["provider"=>$p]); }

// Provider sends record to patient (only verified providers)
if($method==='POST' && $path==='/api/provider/records'){
 $a=auth('provider'); $prov=requireVerifiedProvider($a);
 $in=inp(); if(empty($in["kid_number"])||empty($in["record_type"])) jsonResp(false,"kid_number and record_type required",null,"VALIDATION_ERROR",422);
 $pat=getPatientByKid($in["kid_number"]); if(!$pat) jsonResp(false,"Patient not found",null,"NOT_FOUND",404);
 global $pdo;
 $pdo->prepare("INSERT INTO health_records (patient_id, provider_id, record_type, issued_by, issued_date, summary, details) VALUES (?,?,?,?,?,?,?)")->execute([$pat["id"], $prov["id"], $in["record_type"], $in["issued_by"]??$prov["provider_name"], $in["issued_date"]??date('Y-m-d'), $in["summary"]??'', $in["details"]??'']);
 $rid=$pdo->lastInsertId();
 logActivity($pat["id"],"Record Received","{$in["record_type"]} received from {$prov["provider_name"]}", $prov["id"], "provider", $rid, null, "received");
 notifyPatient($pat["id"],"new_record","New Health Record","You received {$in["record_type"]} from {$prov["provider_name"]}", $rid, "health_record");
 jsonResp(true,"Record sent to patient",["record_id"=>$rid],null,201);
}

// Provider can also request access (same as org flow but with provider role)
if($method==='POST' && $path==='/api/provider/requests'){
 $a=auth('provider'); $prov=requireVerifiedProvider($a);
 $in=inp(); $pat=getPatientByKid($in["kid_number"]??''); if(!$pat) jsonResp(false,"Patient not found",null,"NOT_FOUND",404);
 global $pdo; $pdo->prepare("INSERT INTO record_requests (patient_id, requester_id, requester_type, record_type, reason) VALUES (?,?,?,?,?)")->execute([$pat["id"], $prov["id"], 'provider', $in["record_type"], $in["reason"]??'']);
 $rid=$pdo->lastInsertId(); notifyPatient($pat["id"],"new_request","New Request","{$prov["provider_name"]} requested {$in["record_type"]}", $rid, "record_request"); logActivity($pat["id"],"Request Received","Provider {$prov["provider_name"]} requested {$in["record_type"]}", $prov["id"],"provider", null, $rid, "pending");
 jsonResp(true,"Request sent",["request_id"=>$rid],null,201);
}

// ==================== PATIENT AUTH ====================
if($method==='POST' && $path==='/api/patient/register'){
 $in=inp(); foreach(["first_name","last_name","phone","date_of_birth","password"] as $f) if(empty($in[$f])) jsonResp(false,"$f required",null,"VALIDATION_ERROR",422);
 // Auto-generate KID
 $kid=$in["kid_number"]??genKid(); while(getPatientByKid($kid)) $kid=genKid();
 global $pdo; $hash=password_hash($in["password"],PASSWORD_BCRYPT);
 try{ $pdo->prepare("INSERT INTO patients (kid_number, first_name, last_name, phone, email, date_of_birth, password_hash) VALUES (?,?,?,?,?,?,?)")->execute([$kid,$in["first_name"],$in["last_name"],$in["phone"],$in["email"]??null,$in["date_of_birth"],$hash]); }catch(Exception $e){ jsonResp(false,"Phone or email already exists or invalid: ".$e->getMessage(),null,"VALIDATION_ERROR",409); }
 $pid=$pdo->lastInsertId(); logActivity($pid,"Account Created","Patient account created for $kid",$pid,"patient",null,null,"created"); jsonResp(true,"Patient registered",["kid_number"=>$kid,"id"=>$pid],null,201);
}
if($method==='POST' && $path==='/api/patient/login'){
 $in=inp(); $login=$in["email"]??$in["phone"]??$in["kid_number"]??''; if(!$login) jsonResp(false,"email/phone/kid_number required",null,"VALIDATION_ERROR",422);
 global $pdo; $s=$pdo->prepare("SELECT * FROM patients WHERE email=? OR phone=? OR kid_number=?"); $s->execute([$login,$login,$login]); $p=$s->fetch(); if(!$p||!password_verify($in["password"]??'',$p["password_hash"])) jsonResp(false,"Invalid credentials",null,"UNAUTHORIZED",401);
 $tok=signJwt(["patientId"=>$p["id"],"kid_number"=>$p["kid_number"],"role"=>"patient"], $JWT_SECRET); unset($p["password_hash"]); jsonResp(true,"Login success",["token"=>$tok,"patient"=>$p]);
}
if($method==='GET' && in_array($path,['/api/patient/me','/api/patient/profile'])){ $a=auth('patient'); $p=getPatient($a["patientId"]); if(!$p) jsonResp(false,"Patient not found",null,"NOT_FOUND",404); unset($p["password_hash"]); jsonResp(true,"Patient profile",["patient"=>$p]); }

// PATCH /api/patient/profile - only first_name, last_name, email, phone allowed, reject others with 403
if($method==='PATCH' && $path==='/api/patient/profile'){
 $a=auth('patient'); $in=inp(); $forbidden=["date_of_birth","kid_number","password_hash","id","created_at"]; foreach($forbidden as $f) if(array_key_exists($f,$in)) jsonResp(false,"Field $f cannot be edited",null,"FORBIDDEN",403);
 $allowed=["first_name","last_name","email","phone"]; $sets=[]; $vals=[]; foreach($allowed as $f) if(isset($in[$f])){ $sets[]="$f=?"; $vals[]=$in[$f]; }
 if(!$sets) jsonResp(false,"No valid fields to update",null,"VALIDATION_ERROR",422);
 global $pdo; $vals[]=$a["patientId"]; $pdo->prepare("UPDATE patients SET ".implode(',',$sets)." WHERE id=?")->execute($vals); logActivity($a["patientId"],"Profile Updated","Patient updated profile", $a["patientId"],"patient"); $p=getPatient($a["patientId"]); unset($p["password_hash"]); jsonResp(true,"Profile updated",["patient"=>$p]);
}

// GET /api/patient/records
if($method==='GET' && $path==='/api/patient/records'){
 $a=auth('patient'); global $pdo; $st=$pdo->prepare("SELECT * FROM health_records WHERE patient_id=? ORDER BY created_at DESC"); $st->execute([$a["patientId"]]); $recs=$st->fetchAll(); jsonResp(true,"Records",["records"=>$recs,"total"=>count($recs)]);
}
// GET /api/patient/records/:recordId - ownership check
if($method==='GET' && preg_match('#^/api/patient/records/(\d+)$#',$path,$m)){
 $a=auth('patient'); $rid=(int)$m[1]; global $pdo; $s=$pdo->prepare("SELECT * FROM health_records WHERE id=?"); $s->execute([$rid]); $r=$s->fetch(); if(!$r) jsonResp(false,"Record not found",null,"NOT_FOUND",404); if((int)$r["patient_id"]!== (int)$a["patientId"]) jsonResp(false,"You do not own this record",null,"RECORD_NOT_OWNED",403); jsonResp(true,"Record",["record"=>$r]);
}

// GET /api/patient/requests with filters status and type
if($method==='GET' && $path==='/api/patient/requests'){
 $a=auth('patient'); global $pdo; $sql="SELECT rr.*, COALESCE(p.provider_name, o.org_name) as requester_name FROM record_requests rr LEFT JOIN providers p ON (rr.requester_type='provider' AND p.id=rr.requester_id) LEFT JOIN organisations o ON (rr.requester_type='organisation' AND o.id=rr.requester_id) WHERE rr.patient_id=?"; $params=[$a["patientId"]];
 if(!empty($_GET["status"])){ $sql.=" AND rr.status=?"; $params[]=$_GET["status"]; }
 if(!empty($_GET["type"])){ $sql.=" AND rr.requester_type=?"; $params[]=$_GET["type"]; }
 $sql.=" ORDER BY rr.requested_at DESC"; $st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(); jsonResp(true,"Requests",["requests"=>$rows,"total"=>count($rows)]);
}

// POST approve - generates QR 10 min
if($method==='POST' && preg_match('#^/api/patient/requests/(\d+)/approve$#',$path,$m)){
 $a=auth('patient'); $pid=$a["patientId"]; $rid=(int)$m[1]; global $pdo;
 $st=$pdo->prepare("SELECT * FROM record_requests WHERE id=? AND patient_id=?"); $st->execute([$rid,$pid]); $req=$st->fetch(); if(!$req) jsonResp(false,"Request not found",null,"NOT_FOUND",404);
 if($req["status"]!=='pending') jsonResp(false,"Request already {$req["status"]}",null,"VALIDATION_ERROR",400);
 // Find health_record matching type
 $hr=$pdo->prepare("SELECT * FROM health_records WHERE patient_id=? AND record_type=? ORDER BY issued_date DESC LIMIT 1"); $hr->execute([$pid,$req["record_type"]]); $rec=$hr->fetch(); if(!$rec) jsonResp(false,"No health record of type {$req["record_type"]} found to share",null,"NOT_FOUND",404);
 $pdo->beginTransaction(); try{
  $pdo->prepare("UPDATE record_requests SET status='approved', responded_at=NOW() WHERE id=?")->execute([$rid]);
  $token=genToken(); $expires=date('Y-m-d H:i:s', time()+600);
  $pdo->prepare("INSERT INTO qr_codes (patient_id, record_id, request_id, token, is_used, expires_at) VALUES (?,?,?,?,0,?)")->execute([$pid,$rec["id"],$rid,$token,$expires]);
  // organisation_access for org requests
  if($req["requester_type"]==='organisation'){
   $pdo->prepare("INSERT INTO organisation_access (patient_id, org_id, request_id, status) VALUES (?,?,?, 'active') ON DUPLICATE KEY UPDATE status='active', revoked_at=NULL")->execute([$pid,$req["requester_id"],$rid]);
  }
  logActivity($pid,"Request Approved","Approved {$req["record_type"]} request $rid", $pid,"patient", $rec["id"], $rid, "approved");
  logActivity($pid,"QR Code Generated","QR generated for request $rid, expires $expires", $pid,"patient", $rec["id"], $rid, "generated");
  notifyPatient($pid,"request_approved","Request Approved","You approved {$req["record_type"]} request. QR valid 10 min.", $rid, "record_request");
  $pdo->commit();
 }catch(Exception $e){ $pdo->rollBack(); jsonResp(false,"Approve failed: ".$e->getMessage(),null,"DB_ERROR",500); }
 jsonResp(true,"Request approved. QR code generated and valid for 10 minutes.",["request_id"=>$rid,"status"=>"approved","qr_token"=>$token,"qr_expires_at"=>$expires,"record_id"=>$rec["id"]]);
}

// POST decline
if($method==='POST' && preg_match('#^/api/patient/requests/(\d+)/decline$#',$path,$m)){
 $a=auth('patient'); $pid=$a["patientId"]; $rid=(int)$m[1]; global $pdo; $st=$pdo->prepare("SELECT * FROM record_requests WHERE id=? AND patient_id=?"); $st->execute([$rid,$pid]); $req=$st->fetch(); if(!$req) jsonResp(false,"Request not found",null,"NOT_FOUND",404); if($req["status"]!=='pending') jsonResp(false,"Request already {$req["status"]}",null,"VALIDATION_ERROR",400);
 $pdo->prepare("UPDATE record_requests SET status='declined', responded_at=NOW() WHERE id=?")->execute([$rid]); logActivity($pid,"Request Declined","Declined request $rid for {$req["record_type"]}", $pid,"patient", null, $rid, "declined"); notifyPatient($pid,"request_declined","Request Declined","You declined {$req["record_type"]} request.", $rid, "record_request"); jsonResp(true,"Request declined",["request_id"=>$rid,"status"=>"declined"]);
}

// GET /api/patient/qr/:requestId
if($method==='GET' && preg_match('#^/api/patient/qr/(\d+)$#',$path,$m)){
 $a=auth('patient'); $rid=(int)$m[1]; global $pdo; $s=$pdo->prepare("SELECT rr.* FROM record_requests rr WHERE rr.id=? AND rr.patient_id=?"); $s->execute([$rid,$a["patientId"]]); $req=$s->fetch(); if(!$req) jsonResp(false,"Request not found",null,"NOT_FOUND",404); if($req["status"]!=='approved') jsonResp(false,"Request not approved",null,"REQUEST_NOT_APPROVED",403);
 $q=$pdo->prepare("SELECT * FROM qr_codes WHERE request_id=? ORDER BY created_at DESC LIMIT 1"); $q->execute([$rid]); $qr=$q->fetch(); if(!$qr) jsonResp(false,"QR not found, approve first",null,"NOT_FOUND",404);
 if($qr["is_used"]) jsonResp(false,"QR code already used",null,"QR_USED",410);
 if(strtotime($qr["expires_at"])<time()) jsonResp(false,"QR code expired",null,"QR_EXPIRED",410);
 $remaining=strtotime($qr["expires_at"])-time(); jsonResp(true,"QR valid",["qr_token"=>$qr["token"],"expires_at"=>$qr["expires_at"],"seconds_remaining"=>$remaining]);
}

// POST /api/qr/scan - provider or organisation scans
if($method==='POST' && $path==='/api/qr/scan'){
 $a=auth(); // any role provider/org can scan, patient token invalid for scan? allow provider/org
 if(($a["role"]??'')==='patient') jsonResp(false,"Patients cannot scan own QR",null,"FORBIDDEN",403);
 $in=inp(); $token=$in["qr_token"]??$in["token"]??''; if(!$token) jsonResp(false,"qr_token required",null,"VALIDATION_ERROR",422);
 global $pdo; $s=$pdo->prepare("SELECT qc.*, hr.*, p.kid_number, CONCAT(p.first_name,' ',p.last_name) as patient_name FROM qr_codes qc JOIN health_records hr ON hr.id=qc.record_id JOIN patients p ON p.id=qc.patient_id WHERE qc.token=?"); $s->execute([$token]); $row=$s->fetch(); if(!$row) jsonResp(false,"QR token not found",null,"NOT_FOUND",404);
 if($row["is_used"]) jsonResp(false,"QR code already used",null,"QR_USED",410);
 if(strtotime($row["expires_at"])<time()) jsonResp(false,"QR code expired",null,"QR_EXPIRED",410);
 // For organisation: check organisation_access active
 $reqRow=$pdo->prepare("SELECT * FROM record_requests WHERE id=?"); $reqRow->execute([$row["request_id"]]); $req=$reqRow->fetch();
 if($req && $req["requester_type"]==='organisation'){
   // verify requester matches scanner if organisation
   if(($a["role"]??'')==='organisation' && (int)$a["orgId"] !== (int)$req["requester_id"]) jsonResp(false,"QR not for your organisation",null,"FORBIDDEN",403);
   // check active access
   $acc=$pdo->prepare("SELECT * FROM organisation_access WHERE patient_id=? AND org_id=? AND request_id=? AND status='active'"); $acc->execute([$row["patient_id"], $req["requester_id"], $row["request_id"]]); if(!$acc->fetch()){ // also check general active
     $acc2=$pdo->prepare("SELECT * FROM organisation_access WHERE patient_id=? AND org_id=? AND status='active' LIMIT 1"); $acc2->execute([$row["patient_id"], $req["requester_id"]]); if(!$acc2->fetch()) jsonResp(false,"Access revoked",null,"ACCESS_REVOKED",403);
   }
 }
 if(($a["role"]??'')==='provider' && $req && $req["requester_type"]==='provider' && (int)$a["providerId"] !== (int)$req["requester_id"]) jsonResp(false,"QR not for your provider",null,"FORBIDDEN",403);

 // CRITICAL: mark used BEFORE returning record
 $pdo->prepare("UPDATE qr_codes SET is_used=1, scanned_at=NOW() WHERE id=?")->execute([$row["id"]]);
 $actor_id=$a["providerId"]??$a["orgId"]??null; $actor_type=$a["role"];
 logActivity($row["patient_id"],"QR Code Scanned","Your {$row["record_type"]} was viewed by $actor_type $actor_id", $actor_id, $actor_type, $row["record_id"], $row["request_id"], "viewed");
 notifyPatient($row["patient_id"],"qr_scanned","QR Code Scanned","Your {$row["record_type"]} was viewed.", $row["id"], "qr_code");
 jsonResp(true,"QR scanned - record",["record_type"=>$row["record_type"],"issued_by"=>$row["issued_by"],"issued_date"=>$row["issued_date"],"summary"=>$row["summary"],"details"=>$row["details"],"patient_name"=>$row["patient_name"],"kid_number"=>$row["kid_number"],"scanned_at"=>date('c')]);
}

// GET /api/patient/activity
if($method==='GET' && $path==='/api/patient/activity'){
 $a=auth('patient'); global $pdo; $limit=min(100, (int)($_GET["limit"]??20)); $offset=(int)($_GET["offset"]??0);
 $st=$pdo->prepare("SELECT * FROM activity_log WHERE patient_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?"); $st->bindValue(1,$a["patientId"],PDO::PARAM_INT); $st->bindValue(2,$limit,PDO::PARAM_INT); $st->bindValue(3,$offset,PDO::PARAM_INT); $st->execute(); $act=$st->fetchAll();
 $cnt=$pdo->prepare("SELECT COUNT(*) c FROM activity_log WHERE patient_id=?"); $cnt->execute([$a["patientId"]]); $total=$cnt->fetch()["c"];
 jsonResp(true,"Activity log",["activity"=>$act,"total"=>(int)$total]);
}

// GET /api/patient/notifications
if($method==='GET' && $path==='/api/patient/notifications'){
 $a=auth('patient'); global $pdo; $sql="SELECT * FROM notifications WHERE patient_id=?"; $params=[$a["patientId"]];
 if(isset($_GET["is_read"])){ $sql.=" AND is_read=?"; $params[]=($_GET["is_read"]==='true'||$_GET["is_read"]==='1')?1:0; }
 $sql.=" ORDER BY created_at DESC"; $st=$pdo->prepare($sql); $st->execute($params); jsonResp(true,"Notifications",["notifications"=>$st->fetchAll(),"total"=>count($st->fetchAll())]); // fix: fetch twice bug, redo
}
// Fix notifications count properly
if($method==='GET' && $path==='/api/patient/notifications'){
 $a=auth('patient'); global $pdo; $sql="SELECT * FROM notifications WHERE patient_id=?"; $params=[$a["patientId"]]; if(isset($_GET["is_read"])){ $sql.=" AND is_read=?"; $params[]=($_GET["is_read"]==='true'||$_GET["is_read"]==='1')?1:0; } $sql.=" ORDER BY created_at DESC"; $st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(); jsonResp(true,"Notifications",["notifications"=>$rows,"total"=>count($rows)]);
}
if($method==='PATCH' && preg_match('#^/api/patient/notifications/(\d+)/read$#',$path,$m)){
 $a=auth('patient'); global $pdo; $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND patient_id=?")->execute([(int)$m[1],$a["patientId"]]); jsonResp(true,"Notification marked read");
}
if($method==='PATCH' && $path==='/api/patient/notifications/read-all'){
 $a=auth('patient'); global $pdo; $pdo->prepare("UPDATE notifications SET is_read=1 WHERE patient_id=?")->execute([$a["patientId"]]); jsonResp(true,"All notifications marked read");
}

// GET /api/patient/organisations (access list)
if($method==='GET' && $path==='/api/patient/organisations'){
 $a=auth('patient'); global $pdo; $st=$pdo->prepare("SELECT oa.*, o.org_name, o.org_type, o.email FROM organisation_access oa JOIN organisations o ON o.id=oa.org_id WHERE oa.patient_id=? ORDER BY oa.granted_at DESC"); $st->execute([$a["patientId"]]); jsonResp(true,"Organisations with access",["access"=>$st->fetchAll()]);
}

// DELETE /api/patient/organisations/:orgId/revoke (Patient guide 10 + Org guide 7)
if($method==='DELETE' && preg_match('#^/api/patient/organisations/(\d+)/revoke$#',$path,$m)){
 $a=auth('patient'); $pid=$a["patientId"]; $orgId=(int)$m[1]; global $pdo;
 // Verify patient has previously approved request from this org OR has active access
 $chk=$pdo->prepare("SELECT * FROM organisation_access WHERE patient_id=? AND org_id=? AND status='active'"); $chk->execute([$pid,$orgId]); $rows=$chk->fetchAll(); if(!$rows) jsonResp(false,"No active access for this organisation",null,"NOT_FOUND",404);
 $pdo->beginTransaction(); try{
  $pdo->prepare("UPDATE organisation_access SET status='revoked', revoked_at=NOW() WHERE patient_id=? AND org_id=? AND status='active'")->execute([$pid,$orgId]);
  // Invalidate active QR codes linked to those requests
  $reqIds=$pdo->prepare("SELECT id FROM record_requests WHERE patient_id=? AND requester_type='organisation' AND requester_id=?"); $reqIds->execute([$pid,$orgId]); $ids=array_column($reqIds->fetchAll(),'id');
  if($ids){ $in=implode(',',array_map('intval',$ids)); $pdo->exec("UPDATE qr_codes SET is_used=1, scanned_at=NOW() WHERE request_id IN ($in) AND is_used=0"); }
  logActivity($pid,"Access Revoked","Patient revoked access for org $orgId", $pid,"patient", null, null, "revoked");
  notifyPatient($pid,"access_revoked","Access Revoked","You revoked access for organisation ID $orgId. All unused QR codes invalidated.", $orgId, "organisation");
  $pdo->commit();
 }catch(Exception $e){ $pdo->rollBack(); jsonResp(false,"Revoke failed: ".$e->getMessage(),null,"DB_ERROR",500); }
 jsonResp(true,"Access revoked successfully",["org_id"=>$orgId,"revoked_count"=>count($rows)]);
}

// GET /api/patient/dashboard
if($method==='GET' && $path==='/api/patient/dashboard'){
 $a=auth('patient'); $pid=$a["patientId"]; global $pdo;
 $tot=$pdo->prepare("SELECT COUNT(*) c FROM health_records WHERE patient_id=?"); $tot->execute([$pid]); $tot=$tot->fetch()["c"];
 $pend=$pdo->prepare("SELECT COUNT(*) c FROM record_requests WHERE patient_id=? AND status='pending'"); $pend->execute([$pid]); $pend=$pend->fetch()["c"];
 $appr=$pdo->prepare("SELECT COUNT(*) c FROM record_requests WHERE patient_id=? AND status='approved'"); $appr->execute([$pid]); $appr=$appr->fetch()["c"];
 $unread=$pdo->prepare("SELECT COUNT(*) c FROM notifications WHERE patient_id=? AND is_read=0"); $unread->execute([$pid]); $unread=$unread->fetch()["c"];
 $recent=$pdo->prepare("SELECT action, description, created_at FROM activity_log WHERE patient_id=? ORDER BY created_at DESC LIMIT 3"); $recent->execute([$pid]); $recent=$recent->fetchAll();
 $pat=getPatient($pid);
 jsonResp(true,"Patient dashboard",["patient"=>["kid_number"=>$pat["kid_number"],"first_name"=>$pat["first_name"]],"summary"=>["total_records"=>(int)$tot,"pending_requests"=>(int)$pend,"approved_requests"=>(int)$appr,"unread_notifications"=>(int)$unread],"recent_activity"=>$recent]); // note: will fix structure below
}
// Fix dashboard structure
if($method==='GET' && $path==='/api/patient/dashboard'){
 $a=auth('patient'); $pid=$a["patientId"]; global $pdo;
 $tot=$pdo->prepare("SELECT COUNT(*) c FROM health_records WHERE patient_id=?"); $tot->execute([$pid]); $tot=$tot->fetch()["c"];
 $pend=$pdo->prepare("SELECT COUNT(*) c FROM record_requests WHERE patient_id=? AND status='pending'"); $pend->execute([$pid]); $pend=$pend->fetch()["c"];
 $appr=$pdo->prepare("SELECT COUNT(*) c FROM record_requests WHERE patient_id=? AND status='approved'"); $appr->execute([$pid]); $appr=$appr->fetch()["c"];
 $unread=$pdo->prepare("SELECT COUNT(*) c FROM notifications WHERE patient_id=? AND is_read=0"); $unread->execute([$pid]); $unread=$unread->fetch()["c"];
 $recent=$pdo->prepare("SELECT action, description, created_at FROM activity_log WHERE patient_id=? ORDER BY created_at DESC LIMIT 3"); $recent->execute([$pid]); $recent=$recent->fetchAll();
 $pat=getPatient($pid);
 jsonResp(true,"Patient dashboard",["patient"=>["kid_number"=>$pat["kid_number"],"first_name"=>$pat["first_name"]],"summary"=>["total_records"=>(int)$tot,"pending_requests"=>(int)$pend,"approved_requests"=>(int)$appr,"unread_notifications"=>(int)$unread],"recent_activity"=>$recent]);
}

// ==================== ORGANISATION ENDPOINTS (share DB) ====================
if($method==='POST' && $path==='/api/org/register'){
 $in=inp(); foreach(["org_name","org_type","cac_number","authorised_person","email","phone","password"] as $f) if(empty($in[$f])) jsonResp(false,"$f required",null,"VALIDATION_ERROR",422);
 if(!in_array($in["org_type"],['school','employer','insurer','other'])) jsonResp(false,"Invalid org_type",null,"VALIDATION_ERROR",422);
 if(!isValidCAC($in["cac_number"])) jsonResp(false,"Invalid CAC format",null,"VALIDATION_ERROR",422);
 if(!filter_var($in["email"],FILTER_VALIDATE_EMAIL)) jsonResp(false,"Invalid email",null,"VALIDATION_ERROR",422);
 if(strlen($in["password"])<8) jsonResp(false,"Password min 8 chars",null,"VALIDATION_ERROR",422);
 global $pdo; $chk=$pdo->prepare("SELECT id FROM organisations WHERE cac_number=? OR email=?"); $chk->execute([trim($in["cac_number"]),$in["email"]]); if($chk->fetch()) jsonResp(false,"CAC or email already registered",null,"VALIDATION_ERROR",409);
 $hash=password_hash($in["password"],PASSWORD_BCRYPT); $pdo->prepare("INSERT INTO organisations (org_name, org_type, cac_number, authorised_person, email, phone, password_hash, verification_status) VALUES (?,?,?,?,?,?,?,'pending')")->execute([$in["org_name"],$in["org_type"],trim($in["cac_number"]),$in["authorised_person"],$in["email"],$in["phone"],$hash]);
 $id=$pdo->lastInsertId(); $org=getOrg($id); unset($org["password_hash"]); jsonResp(true,"Organisation registered. Awaiting CAC verification.",["organisation"=>$org],null,201);
}
if($method==='POST' && $path==='/api/org/login'){
 $in=inp(); global $pdo; $s=$pdo->prepare("SELECT * FROM organisations WHERE email=?"); $s->execute([$in["email"]??'']); $org=$s->fetch(); if(!$org||!password_verify($in["password"]??'',$org["password_hash"])) jsonResp(false,"Invalid credentials",null,"UNAUTHORIZED",401);
 $tok=signJwt(["orgId"=>$org["id"],"role"=>"organisation","email"=>$org["email"]], $JWT_SECRET); unset($org["password_hash"]); jsonResp(true,"Login successful",["token"=>$tok,"organisation"=>$org]);
}
if($method==='GET' && $path==='/api/org/me'){ $a=auth('organisation'); $org=getOrg($a["orgId"]); unset($org["password_hash"]); jsonResp(true,"Organisation profile",["organisation"=>$org]); }
if($method==='GET' && $path==='/api/org/patients/search'){
 $a=auth('organisation'); $org=requireVerifiedOrg($a); $kid=trim($_GET['kid']??$_GET['kid_number']??''); if(!$kid) jsonResp(false,"kid query required",null,"VALIDATION_ERROR",422);
 $pat=getPatientByKid($kid); if(!$pat) jsonResp(false,"Patient not found",null,"NOT_FOUND",404); jsonResp(true,"Patient found",["kid_number"=>$pat["kid_number"],"first_name"=>$pat["first_name"],"last_name"=>$pat["last_name"]]);
}
if($method==='POST' && $path==='/api/org/requests'){
 $a=auth('organisation'); $org=requireVerifiedOrg($a); $in=inp(); if(empty($in["kid_number"])||empty($in["record_type"])) jsonResp(false,"kid_number and record_type required",null,"VALIDATION_ERROR",422);
 $pat=getPatientByKid($in["kid_number"]); if(!$pat) jsonResp(false,"Patient not found",null,"NOT_FOUND",404);
 global $pdo; $pdo->prepare("INSERT INTO record_requests (patient_id, requester_id, requester_type, record_type, reason) VALUES (?,?,?,?,?)")->execute([$pat["id"],$org["id"],'organisation',$in["record_type"],$in["reason"]??'']); $rid=$pdo->lastInsertId();
 notifyPatient($pat["id"],"new_request","New Organisation Request",$org["org_name"]." requested {$in["record_type"]}",$rid,"record_request"); logActivity($pat["id"],"Request Received","Organisation {$org["org_name"]} requested {$in["record_type"]}",$org["id"],"organisation",null,$rid,"pending");
 jsonResp(true,"Request sent",["request"=>["id"=>$rid,"kid_number"=>$pat["kid_number"],"patient_name"=>$pat["first_name"]." ".$pat["last_name"],"record_type"=>$in["record_type"],"status"=>"pending"]],null,201);
}
if($method==='GET' && $path==='/api/org/requests'){
 $a=auth('organisation'); $org=requireVerifiedOrg($a); global $pdo; $sql="SELECT rr.*, p.kid_number, CONCAT(p.first_name,' ',p.last_name) as patient_name FROM record_requests rr JOIN patients p ON p.id=rr.patient_id WHERE rr.requester_type='organisation' AND rr.requester_id=?"; $params=[$org["id"]]; if(!empty($_GET["status"])){ $sql.=" AND rr.status=?"; $params[]=$_GET["status"]; } $sql.=" ORDER BY rr.requested_at DESC"; $st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(); $out=[]; foreach($rows as $r){ $out[]=["id"=>(int)$r["id"],"kid_number"=>$r["kid_number"],"patient_name"=>$r["patient_name"],"record_type"=>$r["record_type"],"status"=>$r["status"],"requested_at"=>$r["requested_at"],"responded_at"=>$r["responded_at"]]; } jsonResp(true,"Requests",["requests"=>$out,"total"=>count($out)]);
}
if($method==='GET' && preg_match('#^/api/org/requests/(\d+)/record$#',$path,$m)){
 $a=auth('organisation'); $org=requireVerifiedOrg($a); $rid=(int)$m[1]; global $pdo;
 $st=$pdo->prepare("SELECT rr.*, p.kid_number, CONCAT(p.first_name,' ',p.last_name) as patient_name FROM record_requests rr JOIN patients p ON p.id=rr.patient_id WHERE rr.id=? AND rr.requester_type='organisation' AND rr.requester_id=?"); $st->execute([$rid,$org["id"]]); $req=$st->fetch(); if(!$req) jsonResp(false,"Request not found or not owned",null,"NOT_FOUND",404);
 if($req["status"]!=='approved') jsonResp(false,"Request not approved",null,"REQUEST_NOT_APPROVED",403);
 // Check organisation_access BOTH
 $acc=$pdo->prepare("SELECT * FROM organisation_access WHERE patient_id=? AND org_id=? AND request_id=?"); $acc->execute([$req["patient_id"],$org["id"],$rid]); $access=$acc->fetch();
 if(!$access || $access["status"]==='revoked') jsonResp(false,"Access revoked",null,"ACCESS_REVOKED",403);
 $checkActive=$pdo->prepare("SELECT * FROM organisation_access WHERE patient_id=? AND org_id=? AND status='active' LIMIT 1"); $checkActive->execute([$req["patient_id"],$org["id"]]); if(!$checkActive->fetch()) jsonResp(false,"Access revoked",null,"ACCESS_REVOKED",403);
 $hr=$pdo->prepare("SELECT * FROM health_records WHERE patient_id=? AND record_type=? ORDER BY issued_date DESC LIMIT 1"); $hr->execute([$req["patient_id"],$req["record_type"]]); $rec=$hr->fetch(); if(!$rec) jsonResp(false,"Record content not found",null,"NOT_FOUND",404);
 logActivity($req["patient_id"],"Record Viewed",$org["org_name"]." viewed {$req["record_type"]} of {$req["kid_number"]}", $org["id"],"organisation", $rec["id"], $rid, "viewed");
 jsonResp(true,"Record",["record_type"=>$rec["record_type"],"issued_by"=>$rec["issued_by"],"issued_date"=>$rec["issued_date"],"summary"=>$rec["summary"],"details"=>$rec["details"],"patient_name"=>$req["patient_name"],"kid_number"=>$req["kid_number"],"access_granted_at"=>$access["granted_at"]]);
}
if($method==='GET' && $path==='/api/org/dashboard'){
 $a=auth('organisation'); $org=requireVerifiedOrg($a); global $pdo; $oid=$org["id"];
 $tot=$pdo->prepare("SELECT COUNT(*) c FROM record_requests WHERE requester_type='organisation' AND requester_id=?"); $tot->execute([$oid]); $tot=$tot->fetch()["c"];
 $pend=$pdo->prepare("SELECT COUNT(*) c FROM record_requests WHERE requester_type='organisation' AND requester_id=? AND status='pending'"); $pend->execute([$oid]); $pend=$pend->fetch()["c"];
 $appr=$pdo->prepare("SELECT COUNT(*) c FROM record_requests WHERE requester_type='organisation' AND requester_id=? AND status='approved'"); $appr->execute([$oid]); $appr=$appr->fetch()["c"];
 $decl=$pdo->prepare("SELECT COUNT(*) c FROM record_requests WHERE requester_type='organisation' AND requester_id=? AND status='declined'"); $decl->execute([$oid]); $decl=$decl->fetch()["c"];
 $active=$pdo->prepare("SELECT COUNT(*) c FROM organisation_access WHERE org_id=? AND status='active'"); $active->execute([$oid]); $active=$active->fetch()["c"];
 $recent=$pdo->prepare("SELECT action, description, created_at FROM activity_log WHERE actor_type='organisation' AND actor_id=? ORDER BY created_at DESC LIMIT 10"); $recent->execute([$oid]); $recent=$recent->fetchAll();
 jsonResp(true,"Org Dashboard",["organisation"=>["org_name"=>$org["org_name"],"verification_status"=>$org["verification_status"]],"summary"=>["total_requests"=>(int)$tot,"pending_requests"=>(int)$pend,"approved_requests"=>(int)$appr,"declined_requests"=>(int)$decl,"active_access_count"=>(int)$active],"recent_activity"=>$recent]);
}

// 404
jsonResp(false,"Endpoint not found: $method $path",null,"NOT_FOUND",404);
