<?php

/*
 * (c) Infinite Networks <http://www.infinite.net.au>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Infinite\FormBundle\Form\Type;

use Infinite\FormBundle\Form\DataTransformer\CheckboxGridTransformer;
use Infinite\FormBundle\Form\Util\ChoiceListViewAdapter;
use Infinite\FormBundle\Form\Util\LegacyFormUtil;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\ChoiceList\ArrayChoiceList;
use Symfony\Component\Form\ChoiceList\ChoiceListInterface;
use Symfony\Component\Form\ChoiceList\Factory\DefaultChoiceListFactory;
use Symfony\Component\Form\ChoiceList\View\ChoiceListView;
use Symfony\Component\Form\Extension\Core\ChoiceList\ChoiceListInterface as LegacyChoiceListInterface;
use Symfony\Component\Form\Extension\Core\ChoiceList\SimpleChoiceList;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyAccess\PropertyPath;

/**
 * Provides a checkbox grid for non-Doctrine objects (rows and cols set by x/y_choices).
 */
class CheckboxGridType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $accessor = PropertyAccess::createPropertyAccessor();

        /** @var ChoiceListInterface|LegacyChoiceListInterface $yChoiceList */
        $yChoiceList = $options['y_choice_list'];

        if ($yChoiceList instanceof LegacyChoiceListInterface) {
            // LegacyChoiceList is repsonsible for providing labels.
            foreach ($yChoiceList->getRemainingViews() as $view) {
                $labelBase = $view->label;
                $value = $view->value;
                $choice = $view->data;

                $this->buildRow($builder, $options, $choice, $labelBase, $accessor, $value);
            }
        } else {
            foreach ($yChoiceList->getChoices() as $value => $choice) {
                // The $choice object itself can be used as a label if it has __toString or a label path
                $labelBase = $choice;

                // Although if we're using y_choices then look up the label there.
                if ($options['y_choices'] !== null && array_key_exists($choice, $options['y_choices'])) {
                    $labelBase = $options['y_choices'][$choice];
                }

                $this->buildRow($builder, $options, $choice, $labelBase, $accessor, $value);
            }
        }

        $builder->addViewTransformer(new CheckboxGridTransformer($options));
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     * @param $choice
     * @param $labelBase
     * @param $accessor
     * @param $value
     */
    protected function buildRow(FormBuilderInterface $builder, array $options, $choice, $labelBase, PropertyAccessor $accessor, $value)
    {
        $rowOptions = array(
            'cell_filter' => $options['cell_filter'],
            'choice_list' => $options['x_choice_list'],
            'label_path' => $options['x_label_path'],
            'row' => $choice,
            'row_label' => $options['y_label_path'] === null ? $labelBase : $accessor->getValue($choice, $options['y_label_path']),
        );

        $builder->add($value, LegacyFormUtil::getType('Infinite\FormBundle\Form\Type\CheckboxRowType'), $rowOptions);
    }

    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        $view->vars['headers'] = $this->buildChoiceListView($options['x_choice_list'], $options['x_choices'], $options['x_label_path']);
    }

    public function getBlockPrefix()
    {
        return 'infinite_form_checkbox_grid';
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $defaultXChoiceList = function (Options $options) {
            if (!isset($options['x_choices'])) {
                throw new InvalidOptionsException('You must provide the x_choices option.');
            }

            // SF 2.7+: choice lists are not responsible for labels.
            // Strip the labels until we build the choice view later.
            if (class_exists('Symfony\Component\Form\ChoiceList\ArrayChoiceList')) {
                return new ArrayChoiceList(array_keys($options['x_choices']), function ($choice) {
                    return $choice;
                });
            }

            // BC < SF 2.7
            return new SimpleChoiceList($options['x_choices']);
        };

        $defaultYChoiceList = function (Options $options) {
            if (!isset($options['y_choices'])) {
                throw new InvalidOptionsException('You must provide the y_choices option.');
            }

            // SF 2.7+: choice lists are not responsible for labels.
            // Strip the labels for now and look them up later.
            if (class_exists('Symfony\Component\Form\ChoiceList\ArrayChoiceList')) {
                return new ArrayChoiceList(array_keys($options['y_choices']), function ($choice) {
                    return $choice;
                });
            }

            // BC < SF 2.7
            return new SimpleChoiceList($options['y_choices']);
        };

        $resolver->setDefaults(array(
            'class' => null,
            'cell_filter' => null,

            'x_choices' => null,
            'x_choice_list' => $defaultXChoiceList,
            'x_label_path' => null,

            'y_label_path' => null,
            'y_choices' => null,
            'y_choice_list' => $defaultYChoiceList,
        ));

        $resolver->setRequired(array(
            'x_path',
            'y_path',
        ));
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

    /**
     * @param ChoiceListInterface|LegacyChoiceListInterface $choiceList
     * @param array|null                                    $originalChoices
     * @param string|PropertyPath|null                      $labelPath
     *
     * @return ChoiceListView|LegacyChoiceListInterface
     */
    protected function buildChoiceListView($choiceList, $originalChoices, $labelPath)
    {
        // BC for SF < 2.7
        if ($choiceList instanceof LegacyChoiceListInterface) {
            return $choiceList;
        }

        // Build the choice list view the usual way.
        $accessor = PropertyAccess::createPropertyAccessor();

        $choiceListFactory = new DefaultChoiceListFactory();
        $labelCallback = function ($choice) use ($accessor, $originalChoices, $labelPath) {
            // If we stripped the choice labels back in configureOptions then look it up now.
            if ($originalChoices !== null && array_key_exists($choice, $originalChoices)) {
                $choice = $originalChoices[$choice];
            }

            if ($labelPath === null) {
                return (string) $choice;
            } else {
                return $accessor->getValue($choice, $labelPath);
            }
        };

        $choiceListView = $choiceListFactory->createView($choiceList, null, $labelCallback);

        // Wrap it in a custom class so that old form_themes can still call getRemainingViews.
        return new ChoiceListViewAdapter($choiceListView);
    }
}
