<?php

require_once('vendor/autoload.php');

use Medoo\Medoo;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use Pecee\SimpleRouter\SimpleRouter;

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

# The main handling point of Mana Bot's
# payment gateway, license validation and all other details.

define('DISCORD_TOKEN', 'https://discord.com/api/oauth2/token');
define('DISCORD_USER', 'https://discordapp.com/api/users/@me');
define('TEXT_REPLY', array(9923 => 'Authorization code from Discord is expired... please try again by pressing the link on your email once more.', 8823 => 'Invalid Request...', 2231 => 'The license seems to have already been used/is invalid...'));

# Load all the dotenv files.
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

function reply($code, $response)
{
    $data = new stdClass();
    $data->code = $code;
    $data->response = $response;
    header('Content-Type', 'application/json');
    http_response_code($code);
    die(json_encode($data));
}

function getDatabase(): Medoo
{
    return new Medoo(['database_type' => 'mysql', 'database_name' => 'gateway', 'server' => 'localhost', 'username' => $_ENV['DB_USER'], 'password' => $_ENV['DB_PASS']]);
}

SimpleRouter::get('/', function () {
    reply(200, 'The gateway of Mana, the ultimate Yuri (Girl\'s Love) bot, handles payment, license creation, validation and activation.');
});

function random_bool()
{
    return (bool) random_int(0, 1);
}

# We don't need a cryptographically secure license since
# the license is validated via database and not custom validation.
function generateRandomString($length = 8, $timestamp = 30)
{
    return "MIYARY" . substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length) . '-' . $timestamp;
}

# The email sending method,
# copied from Quickbin's (my other site) method.
function email($receipent_mail = '', $name = '', $body = '', $subject = '')
{
    try {
        $email = $_ENV['EMAIL_USERNAME'];
        // Create the SMTP Transport
        $transport = (new Swift_SmtpTransport('smtp.gmail.com', 587, 'tls'))
            ->setUsername($email)
            ->setPassword($_ENV['EMAIL_PASS']);

        // Create the Mailer using your created Transport
        $mailer = new Swift_Mailer($transport);

        // Create a message
        $message = new Swift_Message();

        // Set a "subject"
        $message->setSubject($subject);

        // Set the "From address"
        $message->setFrom([$email => 'Mana Network']);

        $message->setTo([$receipent_mail => $name]);

        // Set the plain-text "Body"
        $message->setBody($body, 'text/html');

        // Send the message
        $result = $mailer->send($message);
        return $result;
    } catch (Exception $e) {
        echo $e->getMessage();
    }
}

SimpleRouter::get('/redeem/', function () {
    if (isset($_GET['state'], $_GET['code'])) {
        $license = base64_decode($_GET['state']);

        $database = getDatabase();
        if ($database->count('licenses', ['license' => $license]) > 0) {
            # Send webhook request to Discord to see whether this request is authentic and retrieve the user's identification.
            $client = new Client();

            $response = $client->request('POST', DISCORD_TOKEN, ['form_params' => ['client_id' => $_ENV['DISCORD_CLIENT'], 'client_secret' => $_ENV['DISCORD_SECRET'], 'grant_type' => 'authorization_code', 'code' => $_GET['code'], 'redirect_uri' => 'https://gateway.manabot.fun/redeem/', 'scope' => 'identify']]);

            # Validate response.
            $body = $response->getBody();
            $token = json_decode($body, true)['access_token'];

            # Perform a GET request to Discord.
            $user = $client->request('GET', DISCORD_USER, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
            $user__decoded = json_decode($user->getBody(), true);
            $id = $user__decoded['id'];

            # Send a webhook request to Mana discord bot.
            $webhook = $client->request('POST', sprintf($_ENV['SERVER_WEBHOOK'], $id), ['form_params' => ['secret' => $_ENV['SERVER_SECRET'], 'id' => $id, 'license' => $license]]);
            $web__response = json_decode($webhook->getBody(), true)['code'];
            if ($web__response === 200) {
                $database->delete('licenses', ['license' => $license]);
                # Redirect the user to the thank you page.
                header('Location: https://manabot.fun/thank-you/');
            } else {
                # Redirect the user to the invalid page.
                header('Location: https://gateway.manabot.fun/invalid/9923/');
            }
        } else {
            # Redirect the user to the possible expired/invalid license?
            header('Location: https://gateway.manabot.fun/invalid/2231/');
        }
    } else {
        header('Location: https://gateway.manabot.fun/invalid/8823/');
    }
});

SimpleRouter::get('/license/{license}/validate/', function ($license) {
    $valid = getDatabase()->count('licenses', ['license' => $license]) > 0;
    reply($valid ? 200 : 401, $valid ? 'The license is valid' : 'The license is invalid');
});

SimpleRouter::get('/license/{license}/activate/', function ($license){
    if(isset($_GET['secret']) && $_GET['secret'] === $_ENV['SERVER_SECRET']){
        $database = getDatabase();
        if($database->count('licenses', ['license' => $license]) > 0){
            $database->delete('licenses', ['license' => $license]);
            reply(200, 'The license was accepted...');
        } else {
            reply(401, 'The license doesn\'t exist.');
        }
    } else {
        reply(400, 'Invalid secret request.');
    }
});

SimpleRouter::post('/license/{license}/activate/', function ($license){
    if(isset($_POST['secret']) && $_POST['secret'] === $_ENV['SERVER_SECRET']){
        $database = getDatabase();
        if($database->count('licenses', ['license' => $license]) > 0){
            $database->delete('licenses', ['license' => $license]);
            reply(200, 'The license was accepted...');
        } else {
            reply(401, 'The license doesn\'t exist.');
        }
    } else {
        reply(400, 'Invalid secret request.');
    }
});

SimpleRouter::get('/invalid/{code}', function ($code) {
    # Get the text response.
    $reply__text = in_array($code, TEXT_REPLY) ? TEXT_REPLY[intval($code)] : 'Invalid Request...';

    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=0.5">
        <title>Mana Network - Invalid Request</title>
        <link rel="preconnect dns-prefetch" href="https://cdn.quickbin.pw/">
        <link rel="stylesheet" href="https://cdn.quickbin.pw/minified/404.min.css">
    </head>
    <body>
        <div class="container">
            <h1 id="text">Mana Network</h1>
            <p>' . $reply__text . '</p>
            <a href="https://manabot.fun">Head back</a>
        </div>
    </body>
    </html>';
});

function calculateTimestamp($total__amount = 0){
    return $total__amount > 3 && $total__amount < 18 ? 30 : ($total__amount >= 6 && $total__amount < 36 ? 180 : 360);
}

# Handles the license creation.
# Please note that we do not disclose our webhook location, so the chances of you finding our endpoint is hard.
SimpleRouter::post('/subscription/' . $_ENV['GATEWAY_ENDPOINT'], function () {
    if (isset($_SERVER['HTTP_USER_AGENT']) && $_SERVER['HTTP_USER_AGENT'] === 'BMC-HTTPS-ROBOT' && isset($_SERVER['HTTP_X_BMC_EVENT']) && isset($_SERVER['HTTP_X_BMC_SIGNATURE'])) {
        # Retrieve BMC's webhook request body only after validating that is indeed from BMC.
        $bmc = json_decode(file_get_contents('php://input'), true)['response'];
        if (isset($bmc) && isset($bmc['supporter_email'], $bmc['number_of_coffees'], $bmc['total_amount'], $bmc['support_created_on'])) {
            $license = generateRandomString(8, calculateTimestamp($bmc['total_amount']));
            getDatabase()->insert('licenses', ['id' => NULL, 'license' => $license]);

            # Send an email containing all the details from the license to the instant activation link.
            email($bmc['supporter_email'], isset($bmc['supporter_name']) ? $bmc['supporter_name'] : "Fellow user", 'Place your email HTML here...', '[Mana] Thank you for purchasing Premium!');
        } else {
            reply(400, 'Invalid request...');
        }
    } else {
        reply(400, 'Forged request detected, please fallback...');
    }
});

SimpleRouter::get('/subscription/' . $_ENV['GATEWAY_ENDPOINT'], function (){
    reply(400, "Please use a POST request.");
});

SimpleRouter::start();
