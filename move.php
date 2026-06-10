<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

include 'config.inc.php';

$id = preg_replace('/^a-zA-Z0-9/', '', $_GET['id']);
$fen = preg_replace('#^-a-zA-Z0-9/ #', '', $_GET['fen']);
$humanMove = preg_replace('/^a-zA-Z0-9/', '', $_GET['humanMove']);

$mysqli = new mysqli("localhost", $myuser, $mypass, $mydb);

// Check connection
if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: " . $mysqli->connect_error;
    exit();
}

$qry = "SELECT history FROM chess WHERE id='$id'";
$result = $mysqli->query($qry);
if (! $result) {
    die("Query failed");
}
if ( $result->num_rows == 0) {
    if (! $mysqli->query("INSERT INTO chess (id) VALUES ('$id')")) {
        die("Failed to insert");
    }
    $result->free_result();
    $result = $mysqli->query($qry);
    if (! $result) {
        die("Query failed");
    }
    if ($result->num_rows == 0) {
        die("Failed to get history");
    }
}

// Associative array
$row = $result->fetch_assoc();
$history = $row['history'];
if (strlen($humanMove) > 0) {
    $history .= (strlen($history) > 0) ? ',' : '';
    $history .= $humanMove;
}

// Free result set
$result->free_result();

$descriptorspec = array(
   0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
   1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
   2 => array("file", "/tmp/ichess-error-output.txt", "w") // stderr is a file to write to
);

$process = proc_open($qchess_exec, $descriptorspec, $pipes, '/tmp', null);
if ($process == false) {
    die("Unable to open");
}
fwrite($pipes[0], "position fen " . $fen . "\n");
fwrite($pipes[0], "go\n");
fwrite($pipes[0], "quit\n");
$res = stream_get_contents($pipes[1]);
fclose($pipes[0]);
fclose($pipes[1]);
proc_close($process);

//echo $res;

$lines = preg_split('/\r|\n/', $res, -1, PREG_SPLIT_NO_EMPTY);
$json = array();
$fen = '';
foreach ($lines as $line) {
    if (str_starts_with($line, 'bestmove ')) {
        $json['bestmove'] = trim(substr($line, 8));
        $history .= (strlen($history) > 0) ? ',' : '';
        $history .= $json['bestmove'];
    }
    elseif (str_starts_with($line, 'info score cp ')) {
        $json['score_cp'] = trim(substr($line, 14));
    }
    elseif (str_starts_with($line, 'info string usermessage ')) {
        $json['usermessage'] = trim(substr($line, 24));
    }
    elseif (str_starts_with($line, 'info string fen ')) {
        $fen = trim(substr($line, 16));
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($json);

$mysqli->query("UPDATE chess SET history='" . $mysqli->real_escape_string($history) . "' WHERE id='$id'");
if (strlen($fen)) {
    $mysqli->query("UPDATE chess SET fen='" . $mysqli->real_escape_string($fen) . "' WHERE id='$id'");
}

$mysqli->close();

?>
