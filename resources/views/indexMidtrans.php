<form id="donationForm">
  <input type="text" name="username" placeholder="Nama Donatur" required />
  <input type="text" name="phone_number" placeholder="Nomor HP" required />
  <input type="number" name="amount" placeholder="Nominal Donasi" required />
  <textarea name="message" placeholder="Pesan (opsional)"></textarea>
  <button type="submit">Donasi Sekarang</button>
</form>

<!-- Midtrans Snap.js -->
<script src="https://app.sandbox.midtrans.com/snap/snap.js" data-client-key="SB-Mid-client-W3SNK6_j7AswcaCp"></script>

<script>
  document.getElementById("donationForm").addEventListener("submit", async function (e) {
    e.preventDefault();

    const form = e.target;
    const data = {
      username: form.username.value,
      phone_number: form.phone_number.value,
      amount: parseFloat(form.amount.value),
      message: form.message.value,
    };

    const category = "zakat";
    const id = 1;

    try {
      const response = await fetch(`http://localhost:8000/api/billing/create/${category}/${id}`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(data),
      });

      const result = await response.json();

      if (!response.ok) {
        alert("Gagal buat tagihan: " + (result.message || result.error));
        return;
      }

      if (result.snap_token) {
        window.snap.pay(result.snap_token, {
          onSuccess: function (res) {
            alert("Pembayaran sukses! Terima kasih.");
            console.log(res);
          },
          onPending: function (res) {
            alert("Pembayaran tertunda. Silakan selesaikan.");
            console.log(res);
          },
          onError: function (res) {
            alert("Terjadi kesalahan saat pembayaran.");
            console.error(res);
          },
          onClose: function () {
            alert("Anda menutup popup tanpa menyelesaikan pembayaran.");
          },
        });
      } else {
        alert("Snap token tidak ditemukan.");
      }

    } catch (err) {
      console.error(err);
      alert("Terjadi kesalahan koneksi ke server.");
    }
  });
</script>
