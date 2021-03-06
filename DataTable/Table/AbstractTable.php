<?php
namespace CrossKnowledge\DataTableBundle\DataTable\Table;

use CrossKnowledge\DataTableBundle\DataTable\ColumnBuilder;
use CrossKnowledge\DataTableBundle\DataTable\Formatter\FormatterInterface;
use CrossKnowledge\DataTableBundle\DataTable\Table\Layout\Bootstrap;
use CrossKnowledge\DataTableBundle\DataTable\Table\Layout\DataTableLayoutInterface;
use CrossKnowledge\DataTableBundle\Table\Element\Column;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Router;
use CrossKnowledge\DataTableBundle\DataTable\Request\PaginateRequest;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

abstract class AbstractTable
{
    /**
     * @var Router
     */
    protected $router;
    /**
     * @var PaginateRequest
     */
    protected $currentRequest;
    /**
     * @var FormFactory
     */
    protected $formFactory;
    /**
     * @var Form
     */
    protected $filterForm;
    /**
     * @var AuthorizationChecker
     */
    protected $authorizationChecker;

    /**
     * @var Column[]
     */
    protected $columns;
    protected $columnsInitialized = false;

    /**
     * @var OptionsResolver
     */
    protected $optionsResolver;

    /**
     * @var array Key value array of options
     */
    protected $options = [];

    /**
     * @var DataTableLayoutInterface
     */
    protected $layout;
    /**
     * @param FormFactory $formFactory
     * @param Router $router
     */
    public function __construct(
        FormFactory $formFactory,
        AuthorizationCheckerInterface $checker,
        Router $router,
        FormatterInterface $formatter,
        DataTableLayoutInterface $layout = null
    ) {
        $this->formFactory = $formFactory;
        $this->router = $router;
        $this->formatter = $formatter;
        $this->authorizationChecker = $checker;
        $this->layout = null === $layout ? new Bootstrap() : $layout;
        $this->optionsResolver = new OptionsResolver();
        $this->initColumnsDefinitions();
        $this->setDefaultOptions($this->optionsResolver);
        $this->configureOptions($this->optionsResolver);
        $this->options = $this->optionsResolver->resolve();
    }
    /**
     *
     * Example implementation

    public function buildColumns(ColumnBuilder $builder)
    {
        $builder->add('Learner.FirstName', new Column('First name title', ['width' => '20%']))
                ->add('Learner.Name', new Column('Last name'));
    }
     *
     * @return array key must be the column field name,
     *               value must be an array of options for https://datatables.net/reference/option/columns
     */
    abstract public function buildColumns(ColumnBuilder $builder);
    /**
     * Must return a \Traversable a traversable element that must contain for each element an ArrayAccess such as
     *      key(colname) => value(db value)
     *
     * The filter should be used there.
     *
     * Example of the expected return
    return [
        'first_name' => 'John',
        'last_name' => 'Doe'
    ];
     * Example:
    return new \PropelCollection();
     *
     * @return \Traversable
     */
    abstract public function getDataIterator();
    /**
     * @return int the total number of rows regardless of filters
     */
    abstract public function getUnfilteredCount();
    /**
     * @return int|false if there is no such count
     */
    abstract public function getFilteredCount();

    private final function setDefaultOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'layout' => $this->layout,
            'client_side_filtering' => false,
            'filter_reload_table_on_change' => false,
            'template' => 'CrossKnowledgeDataTableBundle::default_table.html.twig',
            'data_table_custom_options' => [],
            'has_filter_form' => function() {
                return $this->getFilterForm()->count()>1;
            }
        ]);
    }
    /**
     * Configure the table options
     *
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver){}
    /**
     * @return array
     */
    public function setOptions(array $options)
    {
        $this->options = $this->optionsResolver->resolve(array_merge($this->options, $options));
    }
    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }
    /**
     * Build the filter form
     *
     * @param FormBuilder $builder
     *
     * @return FormBuilder
     */
    public function buildFilterForm(FormBuilder $builder)
    {
        return $builder;
    }
    /**
     * @return string
     */
    public function getAjaxAdditionalParameters()
    {
        return [];
    }
    /**
     * @return string[] should return the content to insert in the rows key(colname) => value(string / html / any)
     */
    public function getOutputRows()
    {
        $t = [];
        foreach ($this->getDataIterator() as $item) {
            $formatted = $this->formatter->formatRow($item,  $this);
            $t[] = $formatted;
        }

        return $t;
    }
    /**
     * @see getColumns() same as getColumns but filtered for datatable JS API
     */
    public function getClientSideColumns()
    {
        $columns = $this->getColumns();
        $clientSideCols = [];
        foreach ($columns as $colid=>$column) {
            $clientSideCols[$colid] = $column->getClientSideDefinition();
        }

        return $clientSideCols;
    }
    /**
     * @param Request $request
     */
    public function handleRequest(Request $request)
    {
        $this->currentRequest = PaginateRequest::fromHttpRequest($request, $this);
    }
    /**
     * @return PaginateRequest
     */
    public function getCurrentRequest()
    {
        return $this->currentRequest;
    }
    /**
     * @return Form|\Symfony\Component\Form\Form
     */
    public function getFilterForm()
    {
        if (null===$this->filterForm) {
            $this->filterForm = $this->buildFilterForm(
                $this->formFactory->createNamedBuilder($this->getTableId().'_filter')
                    ->add('dofilter', 'button')
            )->getForm();
        }

        return $this->filterForm;
    }
    /**
     * @return array key value of variables accessible for renderers.
     */
    public function buildView()
    {
        $viewParameters = [
            'columns' => $this->getClientSideColumns(),
            'data'   => $this->getOutputRows(),
            'datatable' => $this,
            'unfilteredRowsCount' => $this->getUnfilteredCount(),
            'filteredRowsCount' => $this->getFilteredCount(),
        ];

        if ($this->getOptions()['has_filter_form']) {
            $viewParameters['filterForm'] = $this->getFilterForm()->createView();
        }

        return $viewParameters;
    }

    /**
     * Sets the formatter
     *
     * @param FormatterInterface $formatter
     *
     * @return void
     */
    public function setFormatter(FormatterInterface $formatter)
    {
        $this->formatter = $formatter;
    }
    /**
     * @return string a table idenfitier that will be used for ajax requests
     */
    public final function getTableId()
    {
        return $this->tableId;
    }
    /**
     * @return \CrossKnowledge\DataTableBundle\Table\Element\Column[]
     */
    public function getColumns()
    {
        return $this->columns;
    }
    /**
     * Builds the columns definition
     */
    protected function initColumnsDefinitions()
    {
        $builder = new ColumnBuilder();

        $this->buildColumns($builder);

        $this->columns = $builder->getColumns();
        $this->columnsInitialized =  true;
    }
    /**
     * Sets the table identifier
     *
     * @return null
     */
    public final function setTableId($id)
    {
        $this->tableId = $id;
    }
}
