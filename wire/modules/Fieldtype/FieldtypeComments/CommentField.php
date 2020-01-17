<?php namespace ProcessWire;

/**
 * ProcessWire Comments Field
 *
 * Custom “Field” class for Comments fields. 
 *
 * ProcessWire 3.x, Copyright 2020 by Ryan Cramer
 * https://processwire.com
 * 
 * @property int $moderate
 * @property int|bool $redirectAfterPost
 * @property int|bool $quietSave
 * @property string $notificationEmail
 * @property string $fromEmail
 * @property int $notifySpam
 * @property int $useNotify See Comment::flagNotify* constants
 * @property int|bool $useAkismet
 * @property int $deleteSpamDays
 * @property int $depth
 * @property int|bool $sortNewest
 * @property int|bool $useWebsite
 * @property string $dateFormat
 * @property int $useVotes
 * @property int $useStars
 * @property string $useGravatar
 * @property int $schemaVersion
 * 
 * @todo Some more methods from FieldtypeComments can be moved into this class
 *
 */

class CommentField extends Field {

	/**
	 * Find comments matching given selector
	 * 
	 * @param $selectorString
	 * @param array $options
	 * @return CommentArray
	 * 
	 */
	public function find($selectorString, array $options = array()) {
		return $this->getFieldtype()->find($selectorString, $this, $options); 
	}
	
	/**
	 * Return total quantity of comments matching the selector
	 *
	 * @param string|null $selectorString Selector string with query
	 * @return int
	 *
	 */
	public function count($selectorString) {
		return $this->getFieldtype()->count($selectorString, $this); 
	}
	
	/**
	 * Given a comment code or subcode, return the associated comment ID or 0 if it doesn't exist
	 *
	 * @param Page|int|string $page
	 * @param string $code
	 * @return Comment|null
	 *
	 */
	public function getCommentByCode($page, $code) {
		return $this->getFieldtype()->getCommentByCode($page, $this, $code);
	}

	/**
	 * Get a comment by ID or NULL if not found
	 *
	 * @param Page|int|string $page
	 * @param int $id
	 * @return Comment|null
	 *
	 */
	public function getCommentByID($page, $id) {
		return $this->getFieldtype()->getCommentByID($page, $this, $id); 
	}
	
	/**
	 * Update specific properties for a comment
	 *
	 * @param Page $page
	 * @param Comment $comment
	 * @param array $properties Associative array of properties to update
	 * @return mixed
	 *
	 */
	public function updateComment(Page $page, Comment $comment, array $properties) {
		return $this->getFieldtype()->updateComment($page, $this, $comment, $properties);
	}

	/**
	 * Delete a given comment
	 *
	 * @param Page $page
	 * @param Comment $comment
	 * @param string $notes
	 * @return mixed
	 *
	 */
	public function deleteComment(Page $page, Comment $comment, $notes = '') {
		return $this->getFieldtype()->deleteComment($page, $this, $comment, $notes);
	}
	
	/**
	 * Add a vote to the current comment from the current user/IP
	 *
	 * @param Page $page
	 * @param Comment $comment
	 * @param bool $up Specify true for upvote, or false for downvote
	 * @return bool Returns true on success, false on failure or duplicate
	 *
	 */
	public function voteComment(Page $page, Comment $comment, $up = true) {
		return $this->getFieldtype()->voteComment($page, $this, $comment, $up); 
	}

	/**
	 * Allow given Comment to have given parent comment?
	 * 
	 * @param Comment $comment
	 * @param Comment|int $parent
	 * @param bool $verbose Report reason why not to standard errors? (default=false)
	 * @return bool
	 * @since 3.0.149
	 * 
	 */
	public function allowCommentParent(Comment $comment, $parent, $verbose = false) {
		
		$parentID = $parent instanceof Comment ? (int) $parent->id : (int) $parent;
		if($parentID === 0) return true; // comment with no parent is always allowed
		
		$error = "Comment $comment->id cannot be reply-to comment $parentID — ";
		$commentField = $comment->getField();
		$commentPage = $comment->getPage();
		
		if(!$commentField) $commentField = $this;
		
		if("$commentField" !== "$this") {
			if($verbose) $this->error("$error Comments cannot be moved between fields ($commentField != $this)");
			return false;
		}

		if($parentID == $comment->id) {
			if($verbose) $this->error("$error Comment cannot be its own parent");
			return false;
		}

		$maxDepth = (int) $this->get('depth');
		if(!$maxDepth) {
			if($verbose) $this->error("$error Comment depth is not enabled in field settings");
			return false;
		}

		// determine if current page even has the requested parent comment
		$parentComment = false; /** @var bool|Comment $parentComment */
		$pageComments = $commentPage ? $commentPage->get($commentField->name) : array();
		foreach($pageComments as $pageComment) {
			if($pageComment->id === $parentID) $parentComment = $pageComment;
			if($parentComment) break;
		}
		// if($parentComment) $this->message("Found parent comment $parentComment on page " . $comment->getPage()); 

		// if comment is not present here at all, do not allow as a parent
		if(!$parentComment) {
			if($verbose) $this->error("$error Page $commentPage does not have parent comment $parentID");
			return false;
		}

		// if depth would exceed max allowed depth, comment not allowed
		if($parentComment->depth() >= $maxDepth) {
			if($verbose) $this->error("$error Exceeds max allowed depth setting ($maxDepth)");
			return false;
		}

		// if this comment already has the given one as a child, it cannot be its parent
		if($comment->hasChild($parentID, true)) {
			if($verbose) $this->error("$error Comment $parentID is already a child of comment $comment->id");
			return false;
		}

		return true;
	}

	/**
	 * Allow given comment to live on given page?
	 * 
	 * @param Comment $comment
	 * @param Page $page
	 * @param bool $verbose Report reason why not to standard errors? (default=false)
	 * @return bool
	 * @since 3.0.149
	 * 
	 */
	public function allowCommentPage(Comment $comment, Page $page, $verbose = false) {
		$error = "Comment $comment->id cannot be on page $page->id — ";
	
		// check if page has the current comment field
		$commentField = $comment->getField();
		if(!$commentField) $commentField = $this;
		if(!$page->hasField($commentField)) {
			if($verbose) $this->error("$error Page does not have field: $commentField"); 
			return false;
		}

		// if comment is already assigned to the Page then it is allowed
		$commentPage = $comment->getPage();
		if($commentPage && $commentPage->id === $page->id) return true;

		// check if comment has a parent comment
		$parentID = $comment->parent_id;
		if($parentID) {
			$pageComments = $page->get($commentField->name);
			if(!$pageComments || !$pageComments->hasComment($parentID)) {
				if($verbose) $this->error("$error Comment has parent comment $parentID which does not exist on page $page->id"); 
				return false;
			}
		}
		
		return true;
	}

	/**
	 * May the given comment be deleted?
	 * 
	 * @param Comment $comment
	 * @return bool
	 * 
	 */
	public function allowDeleteComment(Comment $comment) {
		$children = $comment->children();
		if(!$children->count()) return true;
		$allow = true;
		foreach($children as $child) {
			if($child->id > 0 && $child->status < Comment::statusDelete) {
				$allow = false;
				break;
			}
		}
		return $allow;
	}

	/**
	 * @return FieldtypeComments|Fieldtype
	 *
	 */
	public function getFieldtype() {
		return parent::getFieldtype();
	}
}	