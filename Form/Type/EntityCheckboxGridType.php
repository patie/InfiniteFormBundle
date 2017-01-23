<?php

/*
 * (c) Infinite Networks <http://www.infinite.net.au>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Infinite\FormBundle\Form\Type;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\Mapping\ClassMetadata;
use Infinite\FormBundle\Form\Util\LegacyFormUtil;
use Infinite\FormBundle\Form\Util\LegacyChoiceListUtil;
use Symfony\Bridge\Doctrine\Form\ChoiceList\ORMQueryBuilderLoader;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

/**
 * Provides a checkbox grid for Doctrine entities.
 */
class EntityCheckboxGridType extends AbstractType
{
    protected $registry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getBlockPrefix()
    {
        return 'infinite_form_entity_checkbox_grid';
    }

    public function getParent()
    {
        // BC for SF < 2.8
        return LegacyFormUtil::getType('Infinite\FormBundle\Form\Type\CheckboxGridType');
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        // X Axis defaults
        $defaultXClass = function (Options $options) {
            /** @var $em \Doctrine\ORM\EntityManager */
            $em = $options['em'];

            return $em->getClassMetadata($options['class'])->getAssociationTargetClass($options['x_path']);
        };

        $defaultXLoader = function (Options $options) {
            if ($options['x_query_builder'] !== null) {
                return new ORMQueryBuilderLoader($options['x_query_builder'], $options['em'], $options['x_class']);
            }

            return null;
        };

        $defaultXChoiceList = function (Options $options) {
            return LegacyChoiceListUtil::createEntityChoiceList(
                $options['em'],
                $options['x_class'],
                $options['x_label_path'],
                $options['x_loader'],
                $options['x_choice_value']
            );
        };

        // Y Axis defaults
        $defaultYClass = function (Options $options) {
            /** @var $em \Doctrine\ORM\EntityManager */
            $em = $options['em'];

            return $em->getClassMetadata($options['class'])->getAssociationTargetClass($options['y_path']);
        };

        $defaultYLoader = function (Options $options) {
            if ($options['y_query_builder'] !== null) {
                return new ORMQueryBuilderLoader($options['y_query_builder'], $options['em'], $options['y_class']);
            }

            return null;
        };

        $defaultYChoiceList = function (Options $options) {
            return LegacyChoiceListUtil::createEntityChoiceList(
                $options['em'],
                $options['y_class'],
                $options['y_label_path'],
                $options['y_loader'],
                $options['y_choice_value']
            );
        };

        $defaultXChoiceValue = function (Options $options) {
            /** @var ClassMetadata $xClassMetadata */
            $xClassMetadata = $options['em']->getClassMetadata($options['x_class']);
            $ids = $xClassMetadata->getIdentifierFieldNames();

            if (count($ids) !== 1) {
                throw new InvalidOptionsException('Could not set x_choice_value automatically. You must specify it manually.');
            }

            return function ($object) use ($xClassMetadata) {
                $ids = $xClassMetadata->getIdentifierValues($object);

                return reset($ids);
            };
        };

        $defaultYChoiceValue = function (Options $options) {
            /** @var ClassMetadata $yClassMetadata */
            $yClassMetadata = $options['em']->getClassMetadata($options['y_class']);
            $ids = $yClassMetadata->getIdentifierFieldNames();

            if (count($ids) !== 1) {
                throw new InvalidOptionsException('Could not set y_choice_value automatically. You must specify it manually.');
            }

            return function ($object) use ($yClassMetadata) {
                $ids = $yClassMetadata->getIdentifierValues($object);

                return reset($ids);
            };
        };

        $resolver->setDefaults(array(
            'em' => null,

            'x_class' => $defaultXClass,
            'x_query_builder' => null,
            'x_loader' => $defaultXLoader,
            'x_choice_value' => $defaultXChoiceValue,
            'x_choice_list' => $defaultXChoiceList,
            'x_label_path' => null,

            'y_class' => $defaultYClass,
            'y_query_builder' => null,
            'y_loader' => $defaultYLoader,
            'y_choice_value' => $defaultYChoiceValue,
            'y_choice_list' => $defaultYChoiceList,
            'y_label_path' => null,

            'cell_filter' => null,
        ));

        $resolver->setRequired(array(
            'class',
            'x_path',
            'y_path',
        ));

        // OptionsResolver 2.6+
        if (method_exists($resolver, 'setNormalizer')) {
            $resolver->setNormalizer('em', $this->getEntityManagerNormalizer());
        } else {
            $resolver->setNormalizers(array(
                'em' => $this->getEntityManagerNormalizer(),
            ));
        }
    }

    // BC for SF < 2.7
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $this->configureOptions($resolver);
    }

    // BC for SF < 2.8
    public function getName()
    {
        return $this->getBlockPrefix();
    }

    private function getEntityManagerNormalizer()
    {
        $registry = $this->registry; // for closures

        // Entity manager 'normaliser' - turns an entity manager name into an entity manager instance
        return function (Options $options, $emName) use ($registry) {
            if ($emName !== null) {
                return $registry->getManager($emName);
            }

            $em = $registry->getManagerForClass($options['class']);

            if ($em === null) {
                throw new InvalidOptionsException(sprintf('"%s" is not a Doctrine entity', $options['class']));
            }

            return $em;
        };
    }
}
