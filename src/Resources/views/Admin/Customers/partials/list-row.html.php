<?php
/** @var \CustomerManagementFrameworkBundle\CustomerView\CustomerViewInterface $cv */
$cv = $this->customerView;

/** @var \CustomerManagementFrameworkBundle\Model\CustomerInterface $customer */
$customer = $this->customer;

$userDetailUrl = null;
if ($cv->hasDetailView($customer)) {
    $userDetailUrl = $this->url('customermanagementframework_admin_customers_detail', [
        'id' => $customer->getId()
    ]);

    $userDetailUrl = $this->formQueryString($this->request, $userDetailUrl);
}
?>

<tr>
    <td class="icon-column icon-column--center">
        <?= $this->template('PimcoreCustomerManagementFrameworkBundle:Admin/Customers/partials:active-state.html.php', [
            'customerView' => $cv,
            'customer' => $customer,
            'language' => $this->language
        ]); ?>
    </td>
     <td class="reference-id-column">
        <?php if (null !== $userDetailUrl): ?>
            <a href="<?= $userDetailUrl ?>"><?= $customer->getId() ?></a>
        <?php else: ?>
            <a href="#" class="js-pimcore-link" data-pimcore-id="<?= $customer->getId() ?>"><?= $customer->getId() ?></a>
        <?php endif; ?>
    </td>
    <td>
        <?= $this->escape($customer->getFirstname()) ?>
        <?= $this->escape($customer->getLastname()) ?>
    </td>
    <td>
        <?= $this->escape($customer->getEmail()) ?>
    </td>

    <td>
        <?= $this->escape($customer->getGender()) ?>
    </td>

    <td>
        <?php foreach ($customer->getAllSegments() as $segment): ?>
            <?= $cv->getViewFormatter()->formatValue($segment); ?>
        <?php endforeach; ?>
    </td>
    
    <td class="edit-id-column">
	    <a href="#" class="btn btn-primary js-pimcore-link" data-pimcore-id="<?= $customer->getId() ?>">
            <?= $customerView->translate('cmf_filters_edit'); ?>
        </a>
    </td>
</tr>
