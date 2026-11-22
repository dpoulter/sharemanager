<?php

    /**
     * Create a screen.
     *
     *   GET  screen_create.php    the form
     *   POST screen_create.php    name=... description=...
     *
     * The form is the New screen button on the screens page. A POST makes an
     * empty screen and lands on its Edit page, where criteria are added with
     * the picker that is already there. includes/screen_create.php checks
     * every field on the server and is the only thing that writes; this file
     * reads the form and says what happened. A refusal shows the form again
     * with the reason and what was typed.
     *
     * The form carries a ticket made when it was shown. A submit whose
     * ticket has already made a screen in this session - the second half of
     * a double click, a resend from the browser - goes to that screen
     * rather than being told the name is taken.
     *
     * config.php applies the same authentication gate as every other page.
     */

    require("../includes/config.php");
    require_once("../includes/screen_create.php");

    $field = function ($name) {
        return (isset($_POST[$name]) && is_string($_POST[$name])) ? trim($_POST[$name]) : '';
    };
    $edit_page = function ($id) { return "screen_list.php?screen=" . (int) $id . "&edit=1"; };

    $message = null;
    $made = (isset($_SESSION['sm_screen_create']) && is_array($_SESSION['sm_screen_create']))
          ? $_SESSION['sm_screen_create'] : [];

    if ($_SERVER["REQUEST_METHOD"] === 'POST') {

        $ticket = $field('ticket');
        if (preg_match('~^[a-f0-9]{32}$~', $ticket) !== 1) { $ticket = ''; }

        if ($ticket !== '' && isset($made[$ticket])) {
            redirect($edit_page($made[$ticket]));
        }

        $result = screen_create([
            'user_id'     => (int) ($_SESSION['id'] ?? 0),
            'name'        => $field('name'),
            'description' => $field('description'),
        ]);

        if ($result['ok']) {
            if ($ticket !== '') {
                $made[$ticket] = (int) $result['id'];
                $_SESSION['sm_screen_create'] = array_slice($made, -20, null, true);
            }
            redirect($edit_page($result['id']));
        }
        $message = $result['why'];
    }

    render("screen_create.php", [
        "title"   => "New screen",
        "message" => $message,
        "ticket"  => bin2hex(random_bytes(16)),
        "posted"  => $_SERVER["REQUEST_METHOD"] === 'POST' ? $_POST : [],
    ]);

?>
