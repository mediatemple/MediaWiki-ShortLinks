<?php
/**
 * MediaWiki ShortLinks
 * Author: Julian Naydichev <jnaydichev@mediatemple.net>
 * 
 * See README for most up-to-date version of the following:
 *
 * Description: 
 *	Allows you to have links like http://wiki.mediatemple.net/a/134 
 *	which redirects the user to
 *	http://wiki.mediatemple.net/w/Serving_websites_with_Nginx_on_Ubuntu_9.10
 *
 *	It will capture new articles upon their creation as well, and create 
 *	their repsective link.
 * 
 * Installation:
 * 	Include this file in your LocalSettings.php, and then visit:
 *	http://your_domain.com/path/to/wiki/index.php?action=installShortLinks
 * 	This creates the databse structure and adds the current articles.
 *	In an article, use <shortlink/> to display that articles link.
 *	There must also be a rewrite rule like the following:
 * 	RewriteRule ^a/[0-9]+/?$	index.php?action=viewShortLink [NC,L]
 *
 * Use:
 *	Once "installed" start adding <shortlink/> tags to your pages,
 *	and sharing those links.
 *
 * Note:
 *	File attachments, or anything that matches the regular expression
 *	\.[a-zA-Z]{3,4}$
 *	Will be excluded from link creation. This is by design.
 *
 * TODO:
 * 	- Add API code to return the shortlink along with requests.
 *	- Rewrite some of the code to work on more setups ($wgArticlePath)
 *	- Take requests from other people and implement them.
 **/

$wgHooks['ArticleSave'][] = 'addToShortlinks';

function addToShortlinks( &$article, &$user, &$text, &$summary, 
	$minor, &$watchthis, $sectionanchor, &$flags, &$status ) {

	$title = $article->getTitle();

	/* don't shortlink files */
	if (preg_match("/\.(gif|jpe?g|png|PNG)$/", $title, $matches) > 0) {
		return true;
	}

	/* connect to the database */
	$db = &wfGetDB(DB_MASTER);

	/* are we installed yet? */
	if (!$db->tableExists('shortlinks'))
		return true;

	/* does the link exist? skip if yes, add if no. */
	$id = $db->selectField('shortlinks', 'id', array('title' => $title->getDBkey()));
	
	if (!($id > 0))
		$db->insert('shortlinks', array( 'title' => $title ) );

	/* and we're done */
	return true;
}

$wgHooks['ParserFirstCallInit'][] = 'displayShortLinkInit';

function displayShortLinkInit( &$parser ) {
	$parser->setHook('shortlink', 'displayShortLink' );
	return true;
}

function displayShortLink( $input, $args, $parser, $frame = '' ) {
	$title = $parser->getTitle()->getDBkey();

	/* no shortlinks for files */
	if (preg_match("/\.[a-zA-Z]{3,4}$/", $title, $matches) > 0) {
		return true;
	}
	
	/* connect to database*/
	$db = &wfGetDB(DB_SLAVE);
	
	/* grab the link */
	$linkID = $db->selectField('shortlinks', 'id', array('title' => $title));

	/* do we have one though? */
	if ($linkID == "") {
		return htmlspecialchars( "No short link for this page, sorry." );
	}
	
	/* return what we do have */
	return htmlspecialchars ('http://' . $_SERVER['SERVER_NAME'] . '/a/' . $linkID);	
}	

/* installation follows */
$wgHooks['UnknownAction'][] = 'installShortLinks';

function installShortLinks( $action, $article = '' ) {
	global $wgOut;

	/* only install if we're being called */
	if ($action != 'installShortLinks') 
		return true;

	$wgOut->setPageTitle('Installing ShortLinks');
	$db = &wfGetDB(DB_MASTER);

	/* are we installed already? */
	$wgOut->addWikiText('Checking if we\'re already installed...');

	if ($db->tableExists('shortlinks')) {
		$wgOut->addWikiText('Already installed, exiting.');
		return false;
	}

	/* not installed, create table */
	$wgOut->addWikiText('Creating table...');
	$table = $db->tableName('shortlinks');

	$create_table = "CREATE  TABLE $table (`id` int(11) NOT NULL auto_increment, "
  			. "`title` varbinary(255) NOT NULL, UNIQUE KEY `id` (`id`), "
  			. "UNIQUE KEY `title` (`title`) )";

	$db->safeQuery($create_table);
	
	/* populate the table */
	$res = $db->select('page', array('distinct page_title'), 
		'page_title NOT REGEXP "\.(gif|jpe?g|png|PNG)$" and page_is_redirect = 0');

	$count = 0;

	/* loop over all articles, and insert to shortlinks table */
	while($title = $db->fetchObject( $res )) {
		$n = $db->selectField('shortlinks', 'id', 
			array('title' => $title->page_title ));
		
		if ($n == '') {
			$db->insert('shortlinks', array( 'title' => $title->page_title ) );
			$wgOut->addWikiText('Added article: ' . $title->page_title);
			$count++;
		} else {
			$wgOut->addWikiText('Skipping: ' . $title->page_title);
		}
	}
	
	$wgOut->addWikiText('Added ' . $count . ' articles.');
	
	return false;
}


$wgHooks['UnknownAction'][] = 'viewShortLink';

function viewShortLink( $action, $article = '' ) {
	if ($action != 'viewShortLink') {
		return true;
	}

	/* get the id */
	$uri = explode('/', $_SERVER['REQUEST_URI']);
	$id = $uri[2];

	/* need our db */
	$db = &wfGetDB(DB_SLAVE);

	/* grab the link from the database */
	$res = $db->selectField('shortlinks', 'title', 
		array('id' =>  mysql_real_escape_string($id) ));
	
	/* does it exist? */
	if ($res == "") 
		$res = "Home";
	
	/* go, man, go! */	
	header("Location: http://" . $_SERVER['SERVER_NAME'] . '/w/' . $res);
	die();
}

$wgHooks['TitleMoveComplete'][] = 'moveShortlink';

function moveShortlink( &$title, &$newTitle, &$user, &$oldid, &$newid) {
	/* connect to the database */
	$db = &wfGetDB(DB_MASTER);

	/* are we installed yet? */
	if (!$db->tableExists('shortlinks'))
		return true;
	
	$oldID = $db->selectField('shortlinks', 'id', array('title' => $title->getDBkey() ));
	if ($oldID > 0) {
		$db->set('shortlinks', 'title', $newTitle->getDBkey(), 'id = ' . $oldID);
	}

	return true;
}

?>
