<?php
declare(strict_types=1);

error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ---------- Protocol ----------
const TCP_PORT              = 10001;
const CMD_COMMON_ANS        = 0x2000;
const CMD_SDK_SERVICE_ASK   = 0x2001;
const CMD_SDK_SERVICE_ANS   = 0x2002;
const CMD_SDK_CMD_ASK       = 0x2003;
const CMD_SDK_CMD_ANS       = 0x2004;
const LOCAL_TCP_VERSION     = 0x1000005;

// ---------- Defaults / Effects ----------
const TEXT_EFFECT_TYPE      = "STAY";
const TEXT_EFFECT_SPEED     = 1;

// Border (off by default)
const USE_BORDER            = false;
const BORDER_INDEX          = 25;
const BORDER_EFFECT         = "rotate";
const BORDER_SPEED          = "slow";

const AVG_CHAR_WIDTH_RATIO  = 0.54;

// ---------- Helpers ----------
function json_fail(int $statusCode, string $msg, array $extra = []): void {
  http_response_code($statusCode);
  echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}
function get_param_str(string $name, ?string $default = null): ?string {
  if (array_key_exists($name, $_GET)) return (string)$_GET[$name];
  return $default;
}
function get_param_int(string $name, int $default): int {
  if (!array_key_exists($name, $_GET) || $_GET[$name] === '') return $default;
  $v = filter_var($_GET[$name], FILTER_VALIDATE_INT);
  return $v === false ? $default : (int)$v;
}
function get_param_int_nullable(string $name): ?int {
  if (!array_key_exists($name, $_GET) || $_GET[$name] === '') return null;
  $v = filter_var($_GET[$name], FILTER_VALIDATE_INT);
  return $v === false ? null : (int)$v;
}
function esc(string $s): string {
  return str_replace(['&','<','>','"',"'",], ['&amp;','&lt;','&gt;','&quot;','&apos;'], $s);
}
function str_len_chars(string $s): int {
  return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}
function auto_font_size(?string $text, int $w, int $h, float $ratio = AVG_CHAR_WIDTH_RATIO): int {
  if ($text === null || $text === '') return max(8, $h - 1);
  $usable_w = max(1, $w - 2);
  $len      = max(1, str_len_chars($text));
  $byWidth  = (int)floor($usable_w / ($len * $ratio));
  $byHeight = $h - 1;
  return max(8, min($byWidth, $byHeight));
}
function seconds_to_hhmmss(int $seconds): string {
  if ($seconds < 0) $seconds = 0;
  $h = intdiv($seconds, 3600);
  $m = intdiv($seconds % 3600, 60);
  $s = $seconds % 60;
  if ($h > 99) { $h = 99; $m = 59; $s = 59; }
  return sprintf('%02d:%02d:%02d', $h, $m, $s);
}
function is_boldish(?string $v): bool {
  if ($v === null) return false;
  $t = strtolower(trim($v));
  return $t === 'bold' || $t === 'true' || $t === '1' || $t === 'yes' || $t === 'on';
}

// ---------- XML builders ----------
function xml_get_if_version_form(int $form): string {
  if ($form === 0) return '<?xml version="1.0" encoding="utf-8"?><sdk><in method="GetIFVersion"><version value="1000000"/></in></sdk>';
  if ($form === 1) return '<?xml version="1.0" encoding="utf-8"?><sdk guid="00000000000000000000000000000000"><in method="GetIFVersion"><version value="1000000"/></in></sdk>';
  return '<?xml version="1.0" encoding="utf-8"?><sdk guid="00000000-0000-0000-0000-000000000000"><in method="GetIFVersion"><version value="1000000"/></in></sdk>';
}
function parse_guid_from_ifversion(string $xmlText): ?string {
  libxml_use_internal_errors(true);
  $sx = simplexml_load_string($xmlText);
  if ($sx === false) return null;
  $guid = (string)$sx['guid'];
  return $guid !== '' ? $guid : null;
}
function xml_open_screen(string $guid): string {
  return '<?xml version="1.0" encoding="utf-8"?><sdk guid="'.esc($guid).'"><in method="OpenScreen"/></sdk>';
}
function build_program_xml(string $name, int $w, int $h, string $text, string $color, string $font, ?int $fontSizeOverride, bool $bold, string $durationHMS): string {
  $fontSize = $fontSizeOverride ?? auto_font_size($text, $w, $h);
  $borderXml = '';
  if (USE_BORDER) $borderXml = '      <border index="'.BORDER_INDEX.'" effect="'.BORDER_EFFECT.'" speed="'.BORDER_SPEED.'"/>'."\n";
  $x = intdiv($w, 2); $y = intdiv($h, 2);
  return
'      <program name="'.esc($name).'" loop="1">'."\n".
$borderXml.
'        <playControl duration="'.esc($durationHMS).'"/>'."\n".
'        <area guid="'.esc($name).'_area">'."\n".
'          <rectangle x="0" y="0" width="'.$w.'" height="'.$h.'"/>'."\n".
'          <resources>'."\n".
'            <text guid="'.esc($name).'_txt" singleLine="false" background="#00000000">'."\n".
'              <x>'.$x.'</x>'."\n".
'              <y>'.$y.'</y>'."\n".
'              <align>center</align>'."\n".
'              <valign>center</valign>'."\n".
'              <effect type="'.esc(TEXT_EFFECT_TYPE).'" speed="'.TEXT_EFFECT_SPEED.'"/>'."\n".
'              <font name="'.esc($font).'" size="'.$fontSize.'" color="'.esc($color).'" bold="'.($bold ? 'true' : 'false').'" italic="false" underline="false"/>'."\n".
'              <lineSpace>5</lineSpace>'."\n".
'              <string>'.esc($text).'</string>'."\n".
'            </text>'."\n".
'          </resources>'."\n".
'        </area>'."\n".
'      </program>';
}
function build_addprogram_xml(string $guid, int $w, int $h, string $textA, bool $hasPageB, ?string $textB, string $color, string $font, ?int $fontSizeA, ?int $fontSizeB, bool $boldA, bool $boldB, string $durationHMS): string {
  $ts = time();
  $prog = build_program_xml('P_A', $w, $h, (string)$textA, $color, $font, $fontSizeA, $boldA, $durationHMS);
  if ($hasPageB) $prog .= "\n".build_program_xml('P_B', $w, $h, (string)$textB, $color, $font, $fontSizeB, $boldB, $durationHMS);
  return
'<?xml version="1.0" encoding="utf-8"?>'."\n".
'<sdk guid="'.esc($guid).'">'."\n".
'  <in method="AddProgram">'."\n".
'    <screen timeStamps="'.$ts.'" width="'.$w.'" height="'.$h.'" rotation="0">'."\n".
$prog."\n".
'    </screen>'."\n".
'  </in>'."\n".
'</sdk>';
}

// ---------- TCP helpers ----------
function recvall($fp, int $n): string {
  $data = ''; $remaining = $n;
  while ($remaining > 0) {
    $chunk = fread($fp, $remaining);
    if ($chunk === false) throw new Exception('socket read error');
    if ($chunk === '') {
      $meta = stream_get_meta_data($fp);
      if ($meta['eof'])       throw new Exception('socket closed');
      if ($meta['timed_out']) throw new Exception('socket read timed out');
      usleep(10000); continue;
    }
    $data .= $chunk; $remaining -= strlen($chunk);
  }
  return $data;
}
function recv_packet($fp): array {
  $lenBytes  = recvall($fp, 2);
  $total_len = unpack('vlen', $lenBytes)['len'];
  $rest      = recvall($fp, $total_len - 2);
  $cmd       = unpack('vcmd', substr($rest, 0, 2))['cmd'];
  $payload   = substr($rest, 2);
  return [$cmd, $payload];
}
function send_service_version($fp, int $version_value): array {
  $pkt = pack('v', 2+2+4) . pack('v', CMD_SDK_SERVICE_ASK) . pack('V', $version_value);
  $written = fwrite($fp, $pkt);
  if ($written === false || $written !== strlen($pkt)) throw new Exception('socket write error (service version)');
  [$cmd, $payload] = recv_packet($fp);

  if ($cmd === CMD_SDK_SERVICE_ANS && strlen($payload) >= 4) {
    $ver = unpack('Vver', substr($payload, 0, 4))['ver'];
    return ['ok', $ver];
  }
  if ($cmd === CMD_COMMON_ANS) {
    $code = strlen($payload) >= 4 ? unpack('Vcode', substr($payload, 0, 4))['code'] : null;
    $msg  = strlen($payload) > 4  ? trim(@iconv('UTF-8','UTF-8//IGNORE', substr($payload, 4))) : '';
    return ['err', [$code, $msg]];
  }
  return ['bad', sprintf('unexpected cmd 0x%04X', $cmd)];
}
function send_sdk_xml($fp, string $xmlStr, int $index = 0): array {
  $xml_len = strlen($xmlStr);
  $pkt = pack('v', 2+2+4+4+$xml_len)
       . pack('v', CMD_SDK_CMD_ASK)
       . pack('V', $xml_len)
       . pack('V', $index)
       . $xmlStr;

  $written = fwrite($fp, $pkt);
  if ($written === false || $written !== strlen($pkt)) throw new Exception('socket write error (sdk xml)');

  [$cmd, $payload] = recv_packet($fp);

  if ($cmd === CMD_SDK_CMD_ANS) {
    if (strlen($payload) < 8) throw new Exception('Short SDK answer header');
    $hdr = unpack('Vxml_len/Vidx', substr($payload, 0, 8));
    $xml = substr($payload, 8, $hdr['xml_len']);
    $xml = @iconv('UTF-8', 'UTF-8//IGNORE', $xml);
    return ['cmd' => $cmd, 'xml' => $xml, 'err' => null];
  }
  if ($cmd === CMD_COMMON_ANS) {
    $err_code = strlen($payload) >= 4 ? unpack('Vcode', substr($payload, 0, 4))['code'] : null;
    $msg      = strlen($payload) > 4  ? trim(@iconv('UTF-8','UTF-8//IGNORE', $payload)) : '';
    return ['cmd' => $cmd, 'xml' => null, 'err' => [$err_code, $msg]];
  }
  return ['cmd' => $cmd, 'xml' => null, 'err' => [null, sprintf('unexpected cmd 0x%04X', $cmd)]];
}
function negotiate_and_get_guid($fp): string {
  [$status, $info] = send_service_version($fp, LOCAL_TCP_VERSION);
  if ($status !== 'ok') throw new Exception('Transport handshake failed: '.json_encode($info));

  $lastErr = null;
  for ($form = 0; $form < 3; $form++) {
    $ans = send_sdk_xml($fp, xml_get_if_version_form($form));
    if ($ans['cmd'] === CMD_SDK_CMD_ANS && !empty($ans['xml'])) {
      $guid = parse_guid_from_ifversion($ans['xml']);
      if ($guid) return $guid;
      $lastErr = ['parsed', 'GetIFVersion returned no guid'];
    } elseif ($ans['cmd'] === CMD_COMMON_ANS) {
      $lastErr = ['common', $ans['err']];
    } else {
      $lastErr = ['unexpected', $ans['err']];
    }
  }
  throw new Exception('GetIFVersion failed; last_err='.json_encode($lastErr));
}

// ---------- Main ----------
try {
  $device_ip = get_param_str('DEVICE_IP');
  if (!$device_ip) json_fail(400, 'Missing required DEVICE_IP');
  if (!filter_var($device_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) json_fail(400, 'Invalid DEVICE_IP; expected IPv4');

  $pageA    = get_param_str('PAGE_A_TEXT', 'System Down');
  $hasPageB = array_key_exists('PAGE_B_TEXT', $_GET);
  $pageB    = $hasPageB ? get_param_str('PAGE_B_TEXT', '') : null;

  $w        = get_param_int('PANEL_W', 80);
  $h        = get_param_int('PANEL_H', 20);
  $color    = get_param_str('COLOR', '#FFFF00');
  $font     = get_param_str('FONT',  'Courier');

  $fontSizeA = get_param_int_nullable('FONT_SIZE_A');
  $fontSizeB = get_param_int_nullable('FONT_SIZE_B');
  $boldA     = is_boldish(get_param_str('FONT_TYPE_A'));
  $boldB     = is_boldish(get_param_str('FONT_TYPE_B'));

  $durationHMS = seconds_to_hhmmss(get_param_int('PROGRAM_DURATION', 1));

  $timeoutSec = 6;
  $fp = @stream_socket_client('tcp://'.$device_ip.':'.TCP_PORT, $errno, $errstr, $timeoutSec, STREAM_CLIENT_CONNECT);
  if (!$fp) json_fail(502, 'TCP connect failed', ['errno' => $errno, 'errstr' => $errstr]);
  stream_set_timeout($fp, $timeoutSec);

  $guid = negotiate_and_get_guid($fp);

  $ansOpen = send_sdk_xml($fp, xml_open_screen($guid));
  if ($ansOpen['cmd'] !== CMD_SDK_CMD_ANS) {
    fclose($fp);
    json_fail(502, 'OpenScreen failed', ['device_error' => $ansOpen['err']]);
  }

  $payload = build_addprogram_xml($guid, $w, $h, $pageA, $hasPageB, $pageB, $color, $font, $fontSizeA, $fontSizeB, $boldA, $boldB, $durationHMS);
  $ansAdd  = send_sdk_xml($fp, $payload);
  fclose($fp);

  if ($ansAdd['cmd'] !== CMD_SDK_CMD_ANS) {
    json_fail(502, 'AddProgram failed', ['device_error' => $ansAdd['err'], 'sentProgramXml' => $payload]);
  }

  echo json_encode([
    'ok'        => true,
    'device_ip' => $device_ip,
    'guid'      => $guid,
    'panel'     => ['w' => $w, 'h' => $h],
    'pages'     => $hasPageB ? 2 : 1,
    'color'     => $color,
    'font'      => $font,
    'sentProgramXml' => $payload,
    'debug'     => [
      'openScreenReplyXml' => $ansOpen['xml'],
      'addProgramReplyXml' => $ansAdd['xml'],
    ],
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if (isset($fp) && is_resource($fp)) { fclose($fp); }
  json_fail(500, 'Unhandled exception', ['message' => $e->getMessage()]);
}
