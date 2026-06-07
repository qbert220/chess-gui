<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$myuser = 'ian';
$mypass = 'No6wwgqYAuBjb[t0';
$mydb = 'ian';
$id = '1';

$mysqli = new mysqli("localhost", $myuser, $mypass, $mydb);

// Check connection
if ($mysqli -> connect_errno) {
  echo "Failed to connect to MySQL: " . $mysqli -> connect_error;
  exit();
}

$qry = "SELECT fen FROM chess WHERE id='$id'";
$result = $mysqli -> query($qry);
if (! $result) {
    die("Query failed");
}
if ( $result->num_rows == 0) {
    echo "Inserting\n";
    if (! $mysqli -> query("INSERT INTO chess (id) VALUES ('$id')")) {
        die("Failed to insert");
    }
    $result = $mysqli -> query($qry);
    if (! $result) {
        die("Query failed");
    }
    if ( $result->num_rows == 0) {
        die("Failed to get FEN");
    }
}

// Associative array
$row = $result -> fetch_assoc();
//echo "FEN: " . $row['fen'];

// Free result set
$result -> free_result();

$mysqli -> close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>qchess</title>
    <meta name="viewport" content="width=device-width, user-scalable=yes, initial-scale=1.0"/>
    <link rel="stylesheet" href="./cm-chessboard/examples/styles/examples.css"/>
    <link rel="stylesheet" href="./cm-chessboard/assets/chessboard.css"/>
    <link rel="stylesheet" href="./cm-chessboard/assets/extensions/markers/markers.css"/>
    <link rel="stylesheet" href="./cm-chessboard/assets/extensions/arrows/arrows.css"/>
    <link rel="stylesheet" href="./cm-chessboard/assets/extensions/promotion-dialog/promotion-dialog.css"/>
    <script src=js/jquery-4.0.0.min.js></script>
</head>
<body>
<p id="message1">None</p>
<p id="message2">None</p>
<div class="board board-large" id="board"></div>
<div id="output"></div>
<p></p>
<p id="message-fen">None</p>

<script type="module">
    import {INPUT_EVENT_TYPE, COLOR, Chessboard, BORDER_TYPE} from "./cm-chessboard/src/Chessboard.js"
    import {MARKER_TYPE, Markers} from "./cm-chessboard/src/extensions/markers/Markers.js"
    import {PROMOTION_DIALOG_RESULT_TYPE, PromotionDialog} from "./cm-chessboard/src/extensions/promotion-dialog/PromotionDialog.js"
    import {Accessibility} from "./cm-chessboard/src/extensions/accessibility/Accessibility.js"
    import {Chess} from "./Chess/chess.js"
    import {RightClickAnnotator} from "./cm-chessboard/src/extensions/right-click-annotator/RightClickAnnotator.js";

    const chess = new Chess()

    function isGameOver() {
        if (chess.isStalemate()) {
            document.getElementById("message1").innerText = 'Stalemate';
            return true;
        }
        if (chess.isCheckmate()) {
            document.getElementById("message1").innerText = 'Checkmate';
            return true;
        }
        if (chess.isDrawByFiftyMoves()) {
            document.getElementById("message1").innerText = 'Draw by 50 move rule';
            return true;
        }
        if (chess.isInsufficientMaterial()) {
            document.getElementById("message1").innerText = 'Draw by insufficient material';
            return true;
        }
        if (chess.isThreefoldRepetition()) {
            document.getElementById("message1").innerText = 'Draw by threefold repetition';
            return true;
        }
        if (chess.isGameOver()) {
            document.getElementById("message1").innerText = 'Game Over';
            return true;
        }
        return false;
    }

    const engineMoveCallback = function(chessboard) {
        return function(data, textStatus, jqXHR) {
            document.getElementById("message1").innerText = JSON.stringify(data);
//            document.getElementById("message2").innerText = textStatus;
            if ((data.bestmove.length == 4) || (data.bestmove.length == 5)) {
                let move = {from: data.bestmove.slice(0, 2), to: data.bestmove.slice(2, 4)};
                if (data.bestmove.length == 5) {
                    move.promotion = data.bestmove.slice(4);
                }
                chess.move(move);
                const fen = chess.fen()
                document.getElementById("message-fen").innerText = fen;
                chessboard.setPosition(fen, true);
                if (! isGameOver()) {
                    chessboard.enableMoveInput(inputHandler, COLOR.white)
                }
            }
        };
    };

    function makeEngineMove(chessboard) {
        if (! isGameOver()) {
            const fen = chess.fen()
            const url = "move.php?id=<?php echo urlencode($id) ?>&fen=" + encodeURIComponent(fen);
            document.getElementById("message-fen").innerText = fen;
            document.getElementById("message2").innerText = url;
            $.get(url, engineMoveCallback(chessboard));
        }
    }

    function inputHandler(event) {
        console.log("inputHandler", event)
        if(event.type === INPUT_EVENT_TYPE.movingOverSquare) {
            return // ignore this event
        }
        if(event.type !== INPUT_EVENT_TYPE.moveInputFinished) {
            event.chessboard.removeLegalMovesMarkers()
        }
        if (event.type === INPUT_EVENT_TYPE.moveInputStarted) {
            // mark legal moves
            const moves = chess.moves({square: event.squareFrom, verbose: true})
            event.chessboard.addLegalMovesMarkers(moves)
            return moves.length > 0
        } else if (event.type === INPUT_EVENT_TYPE.validateMoveInput) {
            const move = {from: event.squareFrom, to: event.squareTo, promotion: event.promotion}
            var result = false;
            try {
                result = chess.move(move)
            } catch (err) {
                console.error(err);
            }
            if (result) {
                event.chessboard.state.moveInputProcess.then(() => { // wait for the move input process has finished
                    event.chessboard.setPosition(chess.fen(), true).then(() => { // update position, maybe castled and wait for animation has finished
                        makeEngineMove(event.chessboard)
                    })
                })
            } else {
                // promotion?
                let possibleMoves = chess.moves({square: event.squareFrom, verbose: true})
                for (const possibleMove of possibleMoves) {
                    if (possibleMove.promotion && possibleMove.to === event.squareTo) {
                        event.chessboard.showPromotionDialog(event.squareTo, COLOR.white, (result) => {
                            console.log("promotion result", result)
                            if (result.type === PROMOTION_DIALOG_RESULT_TYPE.pieceSelected) {
                                chess.move({from: event.squareFrom, to: event.squareTo, promotion: result.piece.charAt(1)})
                                event.chessboard.setPosition(chess.fen(), true)
                                makeEngineMove(event.chessboard)
                            } else {
                                // promotion canceled
                                event.chessboard.enableMoveInput(inputHandler, COLOR.white)
                                event.chessboard.setPosition(chess.fen(), true)
                            }
                        })
                        return true
                    }
                }
            }
            return result;
        } else if (event.type === INPUT_EVENT_TYPE.moveInputFinished) {
            if(event.legalMove) {
                event.chessboard.disableMoveInput()
            }
        }
    }

//    chess.load('4k2r/7p/8/5P2/8/8/8/R3K3 w k - 0 2');


    const board = new Chessboard(document.getElementById("board"), {
        position: chess.fen(),
        assetsUrl: "./cm-chessboard/assets/",
        style: {borderType: BORDER_TYPE.none, pieces: {file: "pieces/staunty.svg"}, animationDuration: 300},
        orientation: COLOR.white,
        extensions: [
            {class: Markers, props: {autoMarkers: MARKER_TYPE.square}},
            {class: RightClickAnnotator},
            {class: PromotionDialog},
            {class: Accessibility, props: {visuallyHidden: true}}
        ]
    })
    board.enableMoveInput(inputHandler, COLOR.white);
    document.getElementById("message-fen").innerText = chess.fen();

</script>
</body>
</html>
