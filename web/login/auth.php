<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (!file_exists('config.php')) die("Erreur : config.php manquant.");
require 'config.php';

$CLIENT_ID     = defined('DISCORD_CLIENT_ID') ? DISCORD_CLIENT_ID : '';
$CLIENT_SECRET = defined('DISCORD_CLIENT_SECRET') ? DISCORD_CLIENT_SECRET : '';
$REDIRECT_URI  = defined('DISCORD_REDIRECT_URI') ? DISCORD_REDIRECT_URI : '';
$GUILD_ID      = defined('DISCORD_GUILD_ID') ? DISCORD_GUILD_ID : '';

session_start();

if (isset($_GET['action']) && $_GET['action'] === 'link') {
    $_SESSION['discord_link_mode'] = true;
    $params = [
        'client_id'     => $CLIENT_ID,
        'redirect_uri'  => $REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'identify guilds.members.read',
        'state'         => 'link_account',
        'prompt'        => 'consent'
    ];
    header('Location: https://discord.com/api/oauth2/authorize?' . http_build_query($params));
    exit;
}

if (!isset($_GET['code'])) {
    header('Location: connexion');
    exit;
}

$ch = curl_init("https://discord.com/api/oauth2/token");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id'     => $CLIENT_ID,
    'client_secret' => $CLIENT_SECRET,
    'grant_type'    => 'authorization_code',
    'code'          => $_GET['code'],
    'redirect_uri'  => $REDIRECT_URI
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$json = json_decode(curl_exec($ch), true);
curl_close($ch);

if (!isset($json['access_token'])) {
    $err = isset($json['error_description']) ? $json['error_description'] : 'Inconnue';
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{background:#0f172a;color:white;font-family:Inter,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;}</style></head><body><div style="text-align:center;"><h2 style="color:#ef4444;">Erreur Discord</h2><p>' . htmlspecialchars($err) . '</p><a href="connexion" style="color:#3b82f6;">Retour</a></div></body></html>';
    exit;
}

$access_token = $json['access_token'];

$ch = curl_init("https://discord.com/api/users/@me");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$user_data = json_decode(curl_exec($ch), true);
curl_close($ch);

$ch = curl_init("https://discord.com/api/users/@me/guilds/$GUILD_ID/member");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$member_data = json_decode(curl_exec($ch), true);
curl_close($ch);

$user_roles = isset($member_data['roles']) ? $member_data['roles'] : [];
$guild_nick = isset($member_data['nick']) ? $member_data['nick'] : null;

$avatar_url = null;
if (!empty($user_data['avatar'])) {
    $avatar_url = 'https://cdn.discordapp.com/avatars/' . $user_data['id'] . '/' . $user_data['avatar'] . '.png?size=128';
}

$discord_info = [
    'discord_id'       => $user_data['id'],
    'discord_username' => $user_data['username'],
    'discord_avatar'   => $avatar_url,
    'discord_nick'     => $guild_nick,
    'discord_roles'    => $user_roles
];

$json_info = json_encode($discord_info);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Liaison Discord...</title>
    <style>
        body { background:#0f172a; color:white; display:flex; justify-content:center; align-items:center; height:100vh; font-family:'Inter',sans-serif; }
        .box { text-align:center; }
        .spinner { display:inline-block; width:30px; height:30px; border:3px solid rgba(255,255,255,0.2); border-top-color:#5865F2; border-radius:50%; animation:spin .6s linear infinite; margin-bottom:15px; }
        @keyframes spin { to{transform:rotate(360deg)} }
    </style>
</head>
<body>
    <div class="box">
        <div class="spinner"></div>
        <p>Liaison du compte Discord en cours...</p>
    </div>
    <script>
        var discordData = <?php echo $json_info; ?>;
        sessionStorage.setItem('discord_link_data', JSON.stringify(discordData));
        var hasToken = false;
        try { hasToken = !!localStorage.getItem('mdt_auth_token'); } catch(e) {}
        window.location.href = hasToken ? 'profil?discord_linked=1' : 'connexion?discord_linked=1';
</script>
</body>
</html>
