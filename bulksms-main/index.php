<?php
if($_SERVER["REQUEST_METHOD"] === "POST"){
$recipientNumber = htmlspecialchars(trim($_POST["recipient_number"]));

/*
// Get recipient phone numbers as an array
    $recipientNumbers = explode(',', $_POST['recipient_numbers']);

    // Remove leading and trailing whitespaces from each number
    $recipientNumbers = array_map('trim', $recipientNumbers);

*/
$message = htmlspecialchars(trim($_POST["message"]));

$accountSid = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx";
$authToken = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx";
$twilioNumber = "+1000000000000000000";

//Start the cURL Connection
//Twilio Endpoint
$twilioEndpoint = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
$ch = curl_init($twilioEndpoint);

//Set cURL config
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Use only for testing purposes
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "{$accountSid}:{$authToken}");
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'To' => $recipientNumber,
        'From' => $twilioNumber,
        'Body' => $message,
    ]);
    //Execute the cURL Request
    $response = curl_exec($ch);
    curl_close($ch);

    //Decode Responses
    $responseData = json_decode($response, true);
   // print_r($response);

   //Check if message was sent
   if($responseData AND isset($responseData["sid"])){
       //Redirect
       header("Location: success.html");
       exit;
   }else{
        //Redirect
       header("Location: error.html");
       exit;
   }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Bulk SMS App</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <form action="" method="POST">
    <label>Recipient's Phone Number</label>
    <input type="text" name="recipient_number" required>

    <label>Message</label>
    <textarea name="message" rows="4" required></textarea>
    <button type="submit">Send SMS</button>
    </form>
</body>
</html>
