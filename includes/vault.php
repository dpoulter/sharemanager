<?php

	/**
	 * The investing vault: markdown notes with YAML frontmatter.
	 *
	 * These are the owner's own notes on how they invest, synced onto the host
	 * from elsewhere. They are what makes the outlook theirs rather than
	 * generic, so they go into the cached system block - stable between calls,
	 * and the thing worth paying the cache write for.
	 *
	 * The directory may be absent, empty or half synced. None of that is an
	 * error: the panel says which notes it used, that list is allowed to be
	 * empty, and everything still works.
	 *
	 * Only the frontmatter is parsed, and only its tags. A note is a file:
	 *
	 *     ---
	 *     title: Position sizing
	 *     tags: [policy, risk]
	 *     ---
	 *     Never more than 5% in one name...
	 *
	 * tags may be a flow list as above or a block list of "- tag" lines.
	 */

	require_once("constants.php");

	function vault_dir() {
		return defined('SM_VAULT_DIR') ? (string) SM_VAULT_DIR : '';
	}

	function vault_word_budget() {
		$budget = defined('SM_VAULT_WORD_BUDGET') ? (int) SM_VAULT_WORD_BUDGET : 6000;
		return $budget > 0 ? $budget : 6000;
	}

	/**
	 * Every readable note, as ["name", "tags", "body", "words"].
	 *
	 * Sorted by name so the cached block is byte identical between calls:
	 * readdir order is not stable, and a reordered system block is a cache
	 * miss, which costs more than it saves.
	 */
	function vault_notes($dir = null) {

		$dir = $dir === null ? vault_dir() : $dir;
		$notes = [];

		if ($dir === '' || !is_dir($dir)) { return $notes; }

		$files = glob(rtrim($dir, '/') . '/*.md');
		if (!is_array($files)) { return $notes; }
		sort($files);

		foreach ($files as $file) {
			if (!is_readable($file)) { continue; }
			$raw = @file_get_contents($file);
			if ($raw === false) { continue; }

			list($tags, $body) = vault_split_frontmatter($raw);

			$notes[] = [
				"name"  => basename($file, '.md'),
				"tags"  => $tags,
				"body"  => $body,
				"words" => str_word_count(strip_tags($body)),
			];
		}

		return $notes;
	}

	/**
	 * Frontmatter tags and the body after it.
	 *
	 * A file with no frontmatter is a note with no tags rather than a parse
	 * error, so a stray markdown file in the directory is simply never
	 * selected.
	 */
	function vault_split_frontmatter($raw) {

		$raw = str_replace("\r\n", "\n", (string) $raw);

		if (strncmp($raw, "---\n", 4) !== 0) { return [[], trim($raw)]; }

		$end = strpos($raw, "\n---", 3);
		if ($end === false) { return [[], trim($raw)]; }

		$front = substr($raw, 4, $end - 3);
		$body  = trim(substr($raw, $end + 4));

		$tags = [];

		//tags: [a, b]
		if (preg_match('/^tags:\s*\[(.*?)\]\s*$/mi', $front, $m)) {
			foreach (explode(',', $m[1]) as $tag) {
				$tag = trim($tag, " \t\"'");
				if ($tag !== '') { $tags[] = strtolower($tag); }
			}
		}
		//tags:
		//  - a
		//  - b
		elseif (preg_match('/^tags:\s*$/mi', $front, $m, PREG_OFFSET_CAPTURE)) {
			$rest = substr($front, $m[0][1]);
			foreach (explode("\n", $rest) as $line) {
				if (preg_match('/^\s*-\s*(.+?)\s*$/', $line, $lm)) {
					$tag = strtolower(trim($lm[1], " \t\"'"));
					if ($tag !== '') { $tags[] = $tag; }
				}
				elseif (trim($line) !== '' && !preg_match('/^tags:/i', $line)) {
					break;   //the next key, so the list has ended
				}
			}
		}

		return [$tags, $body];
	}

	/**
	 * Choose the notes for one outlook, within the word budget.
	 *
	 * $wanted is a list of ["tag" => ..., "class" => ...] in priority order.
	 * class is 'policy', 'sector' or 'strategy': over budget, sector notes go
	 * first, then strategy notes, and policy notes are never dropped. Policy
	 * is how the owner invests, and an outlook that quietly stopped applying
	 * it would be worse than no outlook - it would look the same.
	 *
	 * Returns ["notes" => [...], "names" => [...], "words" => n,
	 *          "dropped" => [...]].
	 */
	function vault_select($wanted, $notes = null, $budget = null) {

		$notes = $notes === null ? vault_notes() : $notes;

		$wanted_by_tag = [];
		foreach ($wanted as $want) { $wanted_by_tag[strtolower($want["tag"])] = $want["class"]; }

		//A note matching more than one wanted tag is kept once, under the
		//class that protects it most.
		$rank = ["policy" => 0, "strategy" => 1, "sector" => 2];
		$chosen = [];
		foreach ($notes as $note) {
			$class = null;
			foreach ($note["tags"] as $tag) {
				if (!isset($wanted_by_tag[$tag])) { continue; }
				$candidate = $wanted_by_tag[$tag];
				if ($class === null || $rank[$candidate] < $rank[$class]) { $class = $candidate; }
			}
			if ($class !== null) {
				$note["class"] = $class;
				$chosen[] = $note;
			}
		}

		//The budget is a parameter so it can be exercised directly; production
		//passes nothing and gets SM_VAULT_WORD_BUDGET.
		$budget  = $budget === null ? vault_word_budget() : (int) $budget;
		$dropped = [];

		//Drop whole notes, worst class first, until it fits. Truncating a note
		//mid-sentence would be worse than leaving it out: the model would
		//apply half a rule without knowing the rest was missing.
		foreach (["sector", "strategy"] as $class) {
			while (vault_words($chosen) > $budget) {
				$index = null;
				foreach ($chosen as $i => $note) {
					if ($note["class"] === $class) { $index = $i; }   //last one first
				}
				if ($index === null) { break; }
				$dropped[] = $chosen[$index]["name"];
				array_splice($chosen, $index, 1);
			}
		}

		return [
			"notes"   => $chosen,
			"names"   => array_map(function ($n) { return $n["name"]; }, $chosen),
			"words"   => vault_words($chosen),
			"dropped" => $dropped,
		];
	}

	function vault_words($notes) {
		$words = 0;
		foreach ($notes as $note) { $words += (int) $note["words"]; }
		return $words;
	}

	/**
	 * The selected notes as one block of text for the cached system prompt.
	 */
	function vault_text($selection) {
		$parts = [];
		foreach ($selection["notes"] as $note) {
			$parts[] = "## " . $note["name"] . "\n"
			         . "(tags: " . implode(', ', $note["tags"]) . ")\n\n"
			         . $note["body"];
		}
		return implode("\n\n", $parts);
	}
