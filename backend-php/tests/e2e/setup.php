<?php
require '/work/vendor/autoload.php';
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
$c = BlobRestProxy::createBlobService(getenv('AZURE_STORAGE_CONNECTION_STRING'));
foreach (['config','data','thumbs'] as $n) { try { $c->createContainer($n); } catch (Throwable $e) {} }
$h = fn($p) => password_hash($p, PASSWORD_BCRYPT);
$put = fn($n, $d) => $c->createBlockBlob('config', $n, json_encode($d, JSON_PRETTY_PRINT));
$put('users.json', [
  ['username'=>'admin','passwordHash'=>$h('adminpass1'),'roles'=>['admin']],
  ['username'=>'alice','passwordHash'=>$h('alicepass1'),'roles'=>['member']],
  ['username'=>'bob','passwordHash'=>$h('bobpass12'),'roles'=>['guest']],
  ['username'=>'dave','passwordHash'=>$h('davepass1'),'roles'=>[]],
]);
$put('roles.json', ['global'=>['view'=>['admin','member'],'upload'=>['admin'],'admin'=>['admin'],'createGallery'=>['member']]]);
$put('galleries.json', [
  ['name'=>'pub','title'=>'Public legacy','roles'=>['view'=>['public','member'],'upload'=>['admin'],'admin'=>['admin']]],
  ['name'=>'priv','title'=>'Private','roles'=>['view'=>['family'],'upload'=>[],'admin'=>[]]],
]);
// sample media
$im = imagecreatetruecolor(800, 600); imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
imagejpeg($im, '/tmp/photo.jpg', 85);
file_put_contents('/tmp/evil.jpg', '<html><script>alert(document.domain)</script></html>');
$o = new MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions(); $o->setContentType('image/jpeg');
$c->createBlockBlob('data', 'pub/seed.jpg', file_get_contents('/tmp/photo.jpg'), $o);
echo "setup ok\n";
