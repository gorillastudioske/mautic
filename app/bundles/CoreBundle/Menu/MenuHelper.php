<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CoreBundle\Menu;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\UserBundle\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Class MenuHelper
 */
class MenuHelper
{

    /**
     * @var CorePermissions
     */
    protected $security;

    /**
     * @var null|Request
     */
    protected $request;

    /**
     * @var User
     */
    protected $user;

    /**
     * Stores items that are assigned to another parent outside it's bundle
     *
     * @var array
     */
    private $orphans = array();

    /**
     * @var array
     */
    protected $mauticParameters;

    /**
     * MenuHelper constructor.
     *
     * @param CorePermissions       $security
     * @param TokenStorageInterface $tokenStorage
     * @param RequestStack          $requestStack
     * @param array                 $mauticParameters
     */
    public function __construct(CorePermissions $security, TokenStorageInterface $tokenStorage, RequestStack $requestStack, array $mauticParameters)
    {
        $this->security         = $security;
        $this->user             = $tokenStorage->getToken()->getUser();
        $this->mauticParameters = $mauticParameters;
        $this->request          = $requestStack->getCurrentRequest();
    }

    /**
     * Converts menu config into something KNP menus expects
     *
     * @param      $items
     * @param int  $depth
     * @param int $defaultPriority
     */
    public function createMenuStructure(&$items, $depth = 0, $defaultPriority = 9999)
    {
        foreach ($items as $k => &$i) {
            if (!is_array($i) || empty($i)) {
                continue;
            }

            if (isset($i['bundle'])) {
                // Category shortcut
                $bundleName = $i['bundle'];
                $i          = array(
                    'access'          => $bundleName.':categories:view',
                    'route'           => 'mautic_category_index',
                    'id'              => 'mautic_'.$bundleName.'category_index',
                    'routeParameters' => array('bundle' => $bundleName),
                );
            }

            // Check to see if menu is restricted
            if (isset($i['access'])) {
                if ($i['access'] == 'admin') {
                    if (!$this->user->isAdmin()) {
                        unset($items[$k]);
                        continue;
                    }
                } elseif (!$this->security->isGranted($i['access'], 'MATCH_ONE')) {
                    unset($items[$k]);
                    continue;
                }
            }

            if (isset($i['checks'])) {
                $passChecks = true;
                foreach ($i['checks'] as $checkGroup => $checks) {
                    foreach ($checks as $name => $value) {
                        if ($checkGroup == 'parameters') {
                            if ($this->getParameter($name) != $value) {
                                $passChecks = false;
                                break;
                            }
                        } elseif ($checkGroup == 'request') {
                            if ($this->request->get($name) != $value) {
                                $passChecks = false;
                                break;
                            }
                        }
                    }
                }
                if (!$passChecks) {
                    unset($items[$k]);
                    continue;
                }
            }

            //Set ID to route name
            if (!isset($i['id'])) {
                if (!empty($i['route'])) {
                    $i['id'] = $i['route'];
                } else {
                    $i['id'] = 'menu-item-'.uniqid();
                }
            }

            //Set link attributes
            if (!isset($i['linkAttributes'])) {
                $i['linkAttributes'] = array(
                    'data-menu-link' => $i['id'],
                    'id'             => $i['id']
                );
            } elseif (!isset($i['linkAttributes']['id'])) {
                $i['linkAttributes']['id']             = $i['id'];
                $i['linkAttributes']['data-menu-link'] = $i['id'];
            } elseif (!isset($i['linkAttributes']['data-menu-link'])) {
                $i['linkAttributes']['data-menu-link'] = $i['id'];
            }

            $i['extras']          = array();
            $i['extras']['depth'] = $depth;

            // Note a divider
            if (!empty($i['divider'])) {
                $i['extras']['divider'] = true;
            }

            // Note a header
            if (!empty($i['header'])) {
                $i['extras']['header'] = $i['header'];
            }

            //Set the icon class for the menu item
            if (!empty($i['iconClass'])) {
                $i['extras']['iconClass'] = $i['iconClass'];
            }

            //Set the actual route name so that it's available to the menu template
            if (isset($i['route'])) {
                $i['extras']['routeName'] = $i['route'];
            }

            //Repeat for sub items
            if (isset($i['children'])) {
                $this->createMenuStructure($i['children'], $depth + 1, $defaultPriority);
            }

            // Determine if this item needs to be listed in a bundle outside it's own
            if (isset($i['parent'])) {
                if (!isset($this->orphans[$i['parent']])) {
                    $this->orphans[$i['parent']] = array();
                }

                $this->orphans[$i['parent']][$k] = $i;

                unset($items[$k]);

                // Don't set a default priority here as it'll assume that of it's parent
            } elseif (!isset($i['priority'])) {
                // Ensure a priority for non-orphans
                $i['priority'] = $defaultPriority;
            }
        }
    }

    /**
     * Get orphaned menu items
     *
     * @return array
     */
    public function getOrphans()
    {
        $orphans = $this->orphans;
        $this->orphans = array();

        return $orphans;
    }

    /**
     * Give orphaned menu items a home
     *
     * @param array $menuItems
     * @param bool  $appendOrphans
     * @param int   $depth
     */
    public function placeOrphans(array &$menuItems, $appendOrphans = false, $depth = 1)
    {
        foreach ($menuItems as $key => &$items) {
            if (isset($this->orphans[$key])) {
                $priority = (isset($items['priority'])) ? $items['priority'] : 9999;
                foreach ($this->orphans[$key] as &$orphan) {
                    if (!isset($orphan['extras'])) {
                        $orphan['extras'] = array();
                    }
                    $orphan['extras']['depth'] = $depth;
                    if (!isset($orphan['priority'])) {
                        $orphan['priority']  = $priority;
                    }
                }

                $items['children'] = (!isset($items['children'])) ?
                    $this->orphans[$key] :
                    $items['children'] = array_merge($items['children'], $this->orphans[$key]);
                unset($this->orphans[$key]);
            } elseif (isset($items['children'])) {
                foreach ($items['children'] as $subKey => $subItems) {
                    $this->placeOrphans($subItems, false, $depth + 1);
                }
            }
        }

        // Append orphans that couldn't find a home
        if ($appendOrphans && count($this->orphans)) {
            $menuItems = array_merge($menuItems, $this->orphans);
            $this->orphans = array();
        }
    }

    /**
     * Sort menu items by priority
     *
     * @param $menuItems
     * @param $defaultPriority
     */
    public function sortByPriority(&$menuItems, $defaultPriority = 9999)
    {
        foreach ($menuItems as &$items) {
            $parentPriority = (isset($items['priority'])) ? $items['priority'] : $defaultPriority;
            if (isset($items['children'])) {
                $this->sortByPriority($items['children'], $parentPriority);
            }
        }

        uasort(
            $menuItems,
            function ($a, $b) use ($defaultPriority) {
                $ap = (isset($a['priority']) ? (int) $a['priority'] : $defaultPriority);
                $bp = (isset($b['priority']) ? (int) $b['priority'] : $defaultPriority);

                if ($ap == $bp) {
                    return 0;
                }

                return ($ap > $bp) ? -1 : 1;
            }
        );
    }


    /**
     * @param $name
     *
     * @return bool
     */
    protected function getParameter($name)
    {
        return isset($this->mauticParameters[$name]) ? $this->mauticParameters[$name] : false;
    }
}
