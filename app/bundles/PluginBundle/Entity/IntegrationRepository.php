<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\PluginBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * IntegrationRepository
 */
class IntegrationRepository extends CommonRepository
{

    public function getIntegrations()
    {
        $services = $this->createQueryBuilder('i')
            ->join('i.plugin', 'p')
            ->getQuery()
            ->getResult();

        $results = array();
        foreach ($services as $s) {
            $results[$s->getName()] = $s;
        }
        return $results;
    }
}
