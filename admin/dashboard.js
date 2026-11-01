window.onpageshow = function (event) {
  if (event.persisted) {
    window.location.reload();
  }
};

const infoBagian = {
  beranda: ["Beranda", "Ringkasan seluruh pengguna portal"],
  pengguna: ["Pengguna", "Tambah, ubah role, reset password, dan hapus akun"],
  aktivitas: ["Aktivitas", "Riwayat tindakan admin terbaru"],
  akun: ["Akun Saya", "Jaga keamanan akun adminmu"],
};

const semuaBagian = document.querySelectorAll(".bagian");
const semuaNav = document.querySelectorAll(".nav");
const judulBagian = document.getElementById("judulBagian");
const subBagian = document.getElementById("subBagian");

function bukaBagian(nama) {
  if (!infoBagian[nama]) {
    nama = "beranda";
  }
  semuaBagian.forEach((b) => b.classList.toggle("aktif", b.id === nama));
  semuaNav.forEach((n) =>
    n.classList.toggle("aktif", n.dataset.bagian === nama),
  );
  judulBagian.textContent = infoBagian[nama][0];
  subBagian.textContent = infoBagian[nama][1];
  history.replaceState(null, "", location.pathname + "#" + nama);
  window.scrollTo({ top: 0, behavior: "smooth" });
}

document.querySelectorAll("[data-bagian]").forEach((tombol) => {
  tombol.addEventListener("click", () => bukaBagian(tombol.dataset.bagian));
});

bukaBagian(location.hash.slice(1));

const jamLive = document.getElementById("jamLive");
const tglLive = document.getElementById("tglLive");

function perbaruiJam() {
  const sekarang = new Date();
  jamLive.textContent = sekarang
    .toLocaleTimeString("id-ID", {
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
    })
    .replace(/\./g, ":");
  tglLive.textContent = sekarang.toLocaleDateString("id-ID", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

perbaruiJam();
setInterval(perbaruiJam, 1000);

const konfirmasi = document.getElementById("konfirmasi");
const teksKonfirmasi = document.getElementById("teksKonfirmasi");
const modalRole = document.getElementById("modalRole");
const modalReset = document.getElementById("modalReset");
let formTertunda = null;

function tutupSemua() {
  konfirmasi.classList.remove("tampil");
  modalRole.classList.remove("tampil");
  modalReset.classList.remove("tampil");
  formTertunda = null;
}

document.querySelectorAll("form[data-konfirm]").forEach((form) => {
  form.addEventListener("submit", (e) => {
    e.preventDefault();
    formTertunda = form;
    teksKonfirmasi.textContent = form.dataset.konfirm;
    konfirmasi.classList.add("tampil");
  });
});

document.getElementById("ya").addEventListener("click", () => {
  if (formTertunda) {
    formTertunda.submit();
  }
});
document.getElementById("batal").addEventListener("click", tutupSemua);

[konfirmasi, modalRole, modalReset].forEach((overlay) => {
  overlay.addEventListener("click", (e) => {
    if (e.target === overlay) {
      tutupSemua();
    }
  });
});

document
  .querySelectorAll("[data-tutup]")
  .forEach((tombol) => tombol.addEventListener("click", tutupSemua));
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    tutupSemua();
  }
});

document.querySelectorAll("[data-role]").forEach((tombol) => {
  tombol.addEventListener("click", () => {
    document.getElementById("roleId").value = tombol.dataset.role;
    document.getElementById("roleNama").textContent = tombol.dataset.nama;
    const radio = modalRole.querySelector(
      'input[name="role"][value="' + tombol.dataset.peran + '"]',
    );
    if (radio) {
      radio.checked = true;
    }
    modalRole.classList.add("tampil");
  });
});

document.querySelectorAll("[data-reset]").forEach((tombol) => {
  tombol.addEventListener("click", () => {
    document.getElementById("resetId").value = tombol.dataset.reset;
    document.getElementById("resetNama").textContent = tombol.dataset.nama;
    document.getElementById("passwordReset").value = "";
    modalReset.classList.add("tampil");
    document.getElementById("passwordReset").focus();
  });
});

const toast = document.getElementById("toast");
if (toast) {
  setTimeout(() => toast.remove(), 4300);
}

const cariUser = document.getElementById("cariUser");
const barisUser = document.querySelectorAll(".user-baris");
const kosongUser = document.getElementById("kosongUser");
const tombolFilter = document.querySelectorAll("[data-filter]");
let peranAktif = "semua";

function terapkanFilter() {
  const kata = cariUser.value.trim().toLowerCase();
  let terlihat = 0;
  barisUser.forEach((baris) => {
    const cocokPeran =
      peranAktif === "semua" || baris.dataset.peran === peranAktif;
    const cocokKata = baris.dataset.cari.includes(kata);
    const tampil = cocokPeran && cocokKata;
    baris.classList.toggle("sembunyi", !tampil);
    if (tampil) {
      terlihat++;
    }
  });
  if (kosongUser) {
    kosongUser.classList.toggle("tampil", terlihat === 0);
  }
}

if (cariUser) {
  cariUser.addEventListener("input", terapkanFilter);
  tombolFilter.forEach((tombol) => {
    tombol.addEventListener("click", () => {
      peranAktif = tombol.dataset.filter;
      tombolFilter.forEach((t) => t.classList.toggle("aktif", t === tombol));
      terapkanFilter();
    });
  });
}

const formAkun = document.getElementById("formAkun");
if (formAkun) {
  formAkun.addEventListener("submit", (e) => {
    const baru = document.getElementById("pwBaru").value;
    const konf = document.getElementById("pwKonf").value;
    const info = document.getElementById("infoCocok");
    if (baru !== konf) {
      e.preventDefault();
      info.textContent = "✗ Konfirmasi password belum cocok";
      info.className = "cek-cocok tdk";
    }
  });
}
