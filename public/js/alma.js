document.addEventListener("DOMContentLoaded", function () {
  const almaUI = document.querySelector(".alma-installment-plan");
  console.log(almaUI);
  if (!almaUI) {
    return;
  }

  const formItems = document.querySelectorAll('form[name="sylius_shop_checkout_select_payment"] input[type="radio"]');
  formItems.forEach(item => {
    item.addEventListener("click", function () {
      document.querySelectorAll(".alma-installment-plan-details").forEach(el => {
        el.classList.add("d-none");
      });

      if (!this.checked) {
        return;
      }
      const selectedPaymentDetails = document.querySelector(".alma-payment-installment-plan-" + this.value + " .alma-installment-plan-details");
      if (!selectedPaymentDetails) {
        return;
      }

      selectedPaymentDetails.classList.remove("d-none");
    });
  });
});
