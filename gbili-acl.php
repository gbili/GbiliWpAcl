<?php
/**
 * @package Gbili_Acl
 * @version 1.6
 */
/*
Plugin Name: Gbili Acl
Plugin URI: http://wordpress.org/extend/plugins/gbili-acl/
Description: Deny access to resources.
Author: Guillermo Devi
Version: 0.1
Author URI: http://c.onfi.gs/
*/

initPlugin();

function initPlugin()
{
    new GbiliAcl(array(
            'edit_posts' => array(
                'categories' => array('private'),
                'pages'      => array('private'),
                'search'     => array(),
            ),
        ), 'wp_');
}

/**
 * This class will use GbiliResourceMatcher and
 * GbiliThemeSwitch to restrict access and to change
 * the theme given some ACL array passed on construction.
 * If the user has more rights than none, it will
 * be served the restricted resource and presented with
 * the theme specified in GbiliThemeSwitch
 * 
 * Usage: create an instance in functions.php passing
 * a privilege mapping to some type of ressource; if not
 * privilege => resource_type => resource_name, resource_name is
 * restricted.
 *
 *  new GbiliAcl(array(
 *      'edit_posts' => array(
 *          'categories' => array('private'),
 *          'pages'      => array('private'),
 *          'search'     => array(),
 *      ),
 *  ));
 *
 *
 */
class GbiliAcl
{
    const ACTION_REDIRECT     = 1;

    const ACTION_THEME_SWITCH = 2;
   
    const NORMAL_FLOW         = 3;

    protected $acl;
    
    protected $userNeeds;

    protected $typeToMethod = array(
        'categories' => 'isCategory',
        'pages'      => 'isPage',
        'posts'      => 'isPost',
        'search'     => 'isSearch',
    );

    public function __construct(array $acl = array(), $tablesPrefix = 'wp_')
    {
        $this->acl = $acl;
        $this->resourceMatcher = new GbiliResourceMatcher($tablesPrefix);

        add_action('wp_head', array($this, 'redirect'));
        add_action('setup_theme', array($this, 'switchTheme'));
    }

    /**
     * Determine what the user needs given some requested resource
     */
    public function getUserNeeds()
    {
        if (null !== $this->userNeeds) {
            return $this->userNeeds;
        }
        foreach ($this->acl as $capability => $restrictedResources) {
            $this->userNeeds = $this->getFlow($capability, $restrictedResources);
        }
        return $this->userNeeds;
    }

    /**
     * If resource is not restricted, serve it normally.
     * else check if user has the right to see it.
     *     if user has the right, switch theme
     *     else redirect to home and serve normally
     *
     */
    public function getFlow($capability, $restrictedResources)
    {
        if (!$this->isRequestInRestrictedResources($restrictedResources)) {
            return self::NORMAL_FLOW;
        }
        return (!current_user_can($capability))? self::ACTION_REDIRECT : self::ACTION_THEME_SWITCH;
    }

    /**
     * Check if actual request is one of the restricted
     * resrouces passed in parameter as an array
     *
     * Use the resource matcher methods to check
     * for every restricted resource type it it is being
     * requested
     * This means that the more restricted resources there are,
     * the more time it will take to check if it is the current
     * resource.
     * Why not check if the current resource is in the restricted
     * array? It is difficult to know the resource type. So? 
     *
     */
    public function isRequestInRestrictedResources($restrictedResources)
    {
        foreach ($restrictedResources as $type => $resources) {
            $isResource = $this->typeToMethod[$type];
            if ($this->resourceMatcher->$isResource($resources)) {
                return true;
            }
        }
        return false;
    }

    public function redirect()
    {
        if ($this->getUserNeeds() === self::ACTION_REDIRECT) {
            wp_redirect(home_url(), 301);
            exit();
        }
    }

    public function switchTheme()
    {
        if ($this->getUserNeeds() === self::ACTION_THEME_SWITCH) {
            new GbiliThemeSwitch();
        }
    }
}

/**
 *
 *
 *
 */
class GbiliResourceMatcher
{
    protected $tablesPrefix = null;
    /**
     * The current category '' if none
     * @var string
     */
    protected $categoriesSlug = array();

    /**
     * A copy of what we know so far from the current post
     */
    protected $post;
    
    /**
     * Guessed from uri
     */
    protected $slug;
    
    /**
     * Register action hooks
     * @return void
     */
    public function __construct($tablesPrefix = 'wp_')
    {
        $this->tablesPrefix = $tablesPrefix;
    }

    public function getCategoriesSlug()
    {
        if (empty($this->categoriesSlug)) {
            $this->categoriesSlug = $this->getCurrentPostCats();
        }
        if (empty($this->categoriesSlug)) {
            $this->categoriesSlug = $this->getCategoriesFromCategoryUri();
        }
        return $this->categoriesSlug;
    }
    
    public function getPost()
    {
        $this->loadPost();
        return $this->post;
    }
    
    public function loadPost()
    {
        if (!$this->triedLoadingPost()) {
            global $post;
            if (null === $post) {
                $post = $this->getPostFromUri();
            }
            $this->post = $post;
        }
        return (false !== $this->post);
    }

    public function isAPost()
    {
        return $this->loadPost();
    }
    
    public function triedLoadingPost()
    {
        return null !== $this->post;
    }
    
    public function getCurrentPostCats()
    {
        if (!$this->isAPost()) {
            return array();
        }
        $cats = get_the_category($this->post->ID);
        if (false === $cats) {
            return array();
        }
        $catSlugs = array();
        foreach ($cats as $cat) {
            $catSlugs[] = $cat->slug;
        }
        return $catSlugs;
    }
    
    public function getPostFromUri()
    {
        if (!preg_match('#[a-z-]+#', $_SERVER['REQUEST_URI'], $matches)) {
            return false;
        }
        global $wpdb;
        $postSlug = $matches[0];
        $post = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT ID FROM ' . $this->tablesPrefix . 'posts WHERE post_name = %s', $postSlug
            )
        );
        return $post;
    }

    public function getSlug()
    {
        if (null === $this->slug) {
            if (!preg_match('#[a-z-]+#', $_SERVER['REQUEST_URI'], $matches)) {
                return false;
            }
            $this->slug = $matches[0];
        }
        return $this->slug;
    }

    public function getCategoriesFromCategoryUri()
    {
        if (!$this->isCategoryListing()) {
            return array();
        }
        $childMostCat = end(array_diff(explode('/', $_SERVER['REQUEST_URI']), array('category', "")));
        $categories = $this->getParentCategories($childMostCat);
        if (false === $categories) {
            return false; //not a category
        }
        $categories[] = $childMostCat;
        return array_unique($categories);
    }
    
    public function getParentCategories($currentCatSlug)
    {
        global $wpdb;
        
        $currentCat = get_category_by_slug($currentCatSlug);
        
        if (!$currentCat) {
            return false;
        }
        
        $rows = $wpdb->get_results(
            "select t.slug, tr.term_id, tr.parent from {$this->tablesPrefix}terms as t left join {$this->tablesPrefix}term_taxonomy as tr on t.term_id = tr.term_id where taxonomy = 'category'", ARRAY_A
        );

        if (empty($rows)) {
            return array();
        }
        $categoryIdToRowKey = array();
        $categorySlugToId = array();
        foreach ($rows as $key => $row) {
            $categoryIdToRowKey[(integer) $row['term_id']] = $key;
            $categorySlugToId[$row['slug']] = (integer) $row['term_id'];
        }
        $categoryIdToSlug = array_flip($categorySlugToId);
        
        if (!isset($categorySlugToId[$currentCatSlug])) {
            return false; //not a category
        }
        
        $parents = array();
        while (($catRowKey = $categoryIdToRowKey[$categorySlugToId[$currentCatSlug]]) && ($parentId = (integer) $rows[$catRowKey]['parent']) !== 0) {
            $currentCatSlug = $categoryIdToSlug[$parentId];
            $parents[] = $currentCatSlug;
        }  
        return $parents;
    }
    
    public function isCategoryListing()
    {
        return 'category' === substr($_SERVER['REQUEST_URI'], 1, 8);
    }

    public function isSearch(array $param)
    {
        return isset($_GET['s']) || isset($_REQUEST['s']) || isset($_POST['s']);
    }

    public function isCategory(array $categories)
    {
        $currentCategories = $this->getCategoriesSlug();
        if (!is_array($currentCategories)) {
            return false;
        }
        $restrictedCats = array_intersect($currentCategories, $categories);
        return !empty($restrictedCats);
    }

    public function isPost(array $posts)
    {
        return in_array($this->getPost(), $posts);
    }

    public function isPage(array $pages)
    {
        $currentPageSlug = $this->getSlug();
        if (!in_array($currentPageSlug, $pages)) {
            return false;
        }
        $restrictedPageSlug = $currentPageSlug;
        return ($page = get_page_by_path($restrictedPageSlug))? ($page->post_type === 'page' && $page->post_name === $restrictedPageSlug) : false;        
    }
}

/**
 *
 * This class allows to change the theme on construction
 * of this class.
 * Usage:
 *    When you construct this object at setup_theme
 *    action hook, theme returned by serveMyTheme 
 *    will be injected in the methods in charge of
 *    serving the theme.
 *    Ultimately the theme will be displayed instead
 *    of the one in your current wordpress options.
 */
class GbiliThemeSwitch
{
    public function __construct()
    {
        add_filter('template', array($this, 'serveMyTheme'));
        add_filter('stylesheet', array($this, 'serveMyTheme'));
        add_filter('pre_option_mods_' . get_current_theme(), '__return_empty_array' );
    }

    public function serveMyTheme($theme)
    {
        return 'twentytwelve';
    }
}
/**/
