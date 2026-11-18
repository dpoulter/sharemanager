<?php /*
 * footer.php
 *
 * Closes the one element header.php opens. It used to close four divs
 * against two opening tags - a <section> and a container - and the extra
 * </div>s closed whatever the page itself had open, so a page whose markup
 * was correct still ended up with its content outside its own card on some
 * browsers. One wrapper, one close.
 *
 * No scripts here. header.php loads the Bootstrap 5 bundle, jQuery and the
 * typeahead; two commented-out copies of the Bootstrap 4.1.3 CDN bundle used
 * to sit at this point. They were already inert, so removing them changes
 * nothing that runs - but loading Bootstrap 4 after Bootstrap 5 is what they
 * would do if anyone uncommented them, and the data-bs-* markup on these
 * pages needs 5.
 */ ?>
</main>
    </body>
</html>
