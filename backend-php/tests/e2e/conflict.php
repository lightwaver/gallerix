<?php
require '/work/vendor/autoload.php';
use Gallerix\{AzureClient, ConfigLoader, ConfigConflictException};
// Two "requests" read users.json, both try to write: the second must be rejected
$a = new ConfigLoader(new AzureClient()); $b = new ConfigLoader(new AzureClient());
$ua = $a->users(); $ub = $b->users();
$ua[] = ['username'=>'from-a','roles'=>[],'passwordHash'=>'x']; $a->saveUsers($ua);
$ub[] = ['username'=>'from-b','roles'=>[],'passwordHash'=>'x'];
try { $b->saveUsers($ub); echo "FAIL concurrent write was not detected\n"; }
catch (ConfigConflictException $e) { echo "PASS concurrent config write rejected (409 message: {$e->getMessage()})\n"; }
$names = array_column((new ConfigLoader(new AzureClient()))->users(), 'username');
echo (in_array('from-a', $names) && !in_array('from-b', $names) ? 'PASS' : 'FAIL') . " first write kept, second not applied\n";
// sequential writes within one loader keep working (ETag refreshed after write)
$ua[] = ['username'=>'from-a2','roles'=>[],'passwordHash'=>'x'];
try { $a->saveUsers($ua); echo "PASS second write from same loader ok\n"; } catch (Throwable $e) { echo "FAIL second write: {$e->getMessage()}\n"; }
// cleanup
$a->saveUsers(array_values(array_filter($ua, fn($u) => !str_starts_with($u['username'], 'from-'))));
