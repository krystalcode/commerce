<?php

namespace Drupal\Tests\commerce_order\Functional;

/**
 * Tests the multiple profile types functionality.
 *
 * @group commerce
 */
class MultipleProfileTypesFieldTest extends OrderBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    \Drupal::service('module_installer')->install(['profile']);
  }

  /**
   * Tests ensuring that the useMultipleProfileTypes field works as expected.
   *
   * @group failing
   */
  public function testUseMultipleProfileTypesFieldExists() {
    $web_assert = $this->assertSession();

    $this->drupalGet('/admin/commerce/config/order-types');

    // Assert that the default order type exists.
    $web_assert->elementContains(
      'css', 'body > div > div > main > div > div > table > tbody > tr > td:nth-child(1)',
      'Default'
    );
    // Click on the 'Edit' link.
    $this->clickLink('Edit');
    $web_assert->statusCodeEquals(200);

    // Verify the useMultipleProfileTypes field exists and is turned off by
    // default.
    $multiple_profile_types_checkbox = $this->xpath(
      '//input[@type="checkbox" and @name="useMultipleProfileTypes" and not(@checked)]'
    );
    $this->assertTrue(
      count($multiple_profile_types_checkbox) === 1,
      'The "use multiple profile types" checkbox exists and is not checked.'
    );
  }

  /**
   * Tests ensuring that the multiple profile types confirm works as expected.
   *
   * @group failing
   */
  public function testMultipleProfileTypesConfirmForm() {
    $web_assert = $this->assertSession();

    // Let's submit the default order type form with the useMultipleProfileTypes
    // checkbox checked.
    $this->drupalGet('/admin/commerce/config/order-types/default/edit');
    $this->submitForm([
      'useMultipleProfileTypes' => TRUE,
    ], t('Save'));

    // Ensure we are taken to the confirm form.
    $web_assert->pageTextContains(t('Are you sure you want to switch to using multiple profile types for shipping and billing for the Default order type?'));
    $web_assert->buttonExists('Switch to Multiple Profile Types');

    // Cancel out of confirming.
    $this->clickLink('Cancel');

    // Verify the useMultipleProfileTypes field has been turned back off.
    $this->drupalGet('/admin/commerce/config/order-types/default/edit');
    $multiple_profile_types_checkbox = $this->xpath(
      '//input[@type="checkbox" and @name="useMultipleProfileTypes" and not(@checked)]'
    );
    $this->assertTrue(
      count($multiple_profile_types_checkbox) === 1,
      'The "use multiple profile types" checkbox exists and is not checked.'
    );
  }

}
