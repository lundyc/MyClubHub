$(function () {
  const playerId = new URLSearchParams(window.location.search).get("id");
  const playerViewUrl = `player_view.php${playerId ? `?id=${encodeURIComponent(playerId)}` : ""}`;
  const modal = document.getElementById("addPaymentModal");

  if (modal) {
    modal.addEventListener("show.bs.modal", function (event) {
      const button = event.relatedTarget;
      if (!button) return;

      const sponsorId = button.getAttribute("data-sponsor") || "";
      const remaining = Number.parseFloat(button.getAttribute("data-remaining") || "0");
      const sponsorInput = document.getElementById("modalSponsorId");
      const remainingInput = document.getElementById("modalRemaining");
      const amountInput = document.getElementById("modalAmount");
      const fillRemaining = document.getElementById("fillRemaining");

      if (sponsorInput) sponsorInput.value = sponsorId;
      if (remainingInput) remainingInput.value = remaining.toFixed(2);
      if (amountInput) amountInput.value = "";

      if (fillRemaining && amountInput) {
        fillRemaining.onclick = function () {
          amountInput.value = remaining.toFixed(2);
        };
      }
    });
  }

  $("#addPaymentForm").on("submit", function (e) {
    e.preventDefault();
    $.post(playerViewUrl, $(this).serialize() + "&ajax_add_payment=1", function (resp) {
      if (resp.success) {
        location.reload();
      } else {
        alert("Error saving payment");
      }
    }, "json");
  });

  $(document).on("click", ".delete-payment", function (e) {
    e.preventDefault();
    if (!confirm("Are you sure you want to delete this payment?")) return;

    const row = $(this).closest("tr");
    const paymentId = $(this).data("id");

    $.post(playerViewUrl, {
      ajax_delete_payment: 1,
      payment_id: paymentId,
    }, function (resp) {
      if (resp.success) {
        row.css("background-color", "#f8d7da").fadeOut(600, function () {
          row.remove();
        });
      } else {
        alert("Error deleting payment");
      }
    }, "json");
  });

  $("#addNoteForm").on("submit", function (e) {
    e.preventDefault();

    const form = $(this);
    const noteText = form.find('textarea[name="note"]').val();

    $.ajax({
      url: playerViewUrl,
      method: "POST",
      data: form.serialize() + "&ajax_add_note=1",
      dataType: "json",
      success: function (resp) {
        if (resp.success) {
          const timestamp = new Date().toLocaleString("en-GB", {
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
          });
          const safeNote = $("<div>").text(noteText).html().replace(/\n/g, "<br>");
          const newNote = `
          <li class="list-group-item">
            <div class="d-flex justify-content-between">
              <span>${safeNote}</span>
              <small class="text-muted">${timestamp}</small>
            </div>
          </li>`;

          $("#notesList").prepend(newNote);
          form[0].reset();

          const successToast = document.getElementById("toastNoteSuccess");
          if (successToast) new bootstrap.Toast(successToast).show();
        } else {
          const errorToast = document.getElementById("toastNoteError");
          if (errorToast) new bootstrap.Toast(errorToast).show();
        }
      },
      error: function () {
        const errorToast = document.getElementById("toastNoteError");
        if (errorToast) new bootstrap.Toast(errorToast).show();
      },
    });
  });
});
