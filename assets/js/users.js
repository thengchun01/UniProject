document.addEventListener("DOMContentLoaded", () => {

    // ================= MODAL HELPERS =================
    const createModal = document.getElementById('createModal');
    const editModal = document.getElementById('editModal');
    const deleteModal = document.getElementById('deleteModal');

    function closeAll() {
        createModal.classList.remove('show');
        editModal.classList.remove('show');
        deleteModal.classList.remove('show');
        document.body.classList.remove('modal-open');
    }

    function openModal(modal) {
        modal.classList.add('show');
        document.body.classList.add('modal-open');
    }

    // ================= OPEN BUTTON =================
    document.getElementById('openCreateModal')
        .addEventListener('click', () => openModal(createModal));

    // ================= CLOSE BUTTONS =================
    document.getElementById('closeCreateModal')
        .addEventListener('click', closeAll);

    document.getElementById('closeEditModal')
        .addEventListener('click', closeAll);

    document.getElementById('closeDeleteModal')
        .addEventListener('click', closeAll);

    // click outside modal closes
    [createModal, editModal, deleteModal].forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeAll();
        });
    });

    // ================= CREATE =================
    document.getElementById('createUserForm').addEventListener('submit', async (e) => {

        e.preventDefault();

        const res = await fetch('user_create.php', {
            method: 'POST',
            body: new FormData(e.target)
        });

        const data = await res.json();

        if (!data.success) 
            return alert(data.message);

        const u = data.user;

        document.querySelector('#userTable tbody').insertAdjacentHTML('afterbegin', `
            <tr data-id="${u.user_id}">
                <td>${u.user_id}</td>
                <td class="col-username">${u.username}</td>
                <td class="col-email">${u.email}</td>
                <td class="col-role">${u.role}</td>
                <td>${u.created_at}</td>
                <td>
                    <button onclick="openEdit(${u.user_id},'${u.username}','${u.email}','${u.role}')">Edit</button>
                    <button onclick="openDelete(${u.user_id})">Delete</button>
                </td>
            </tr>
        `);

        e.target.reset();
        closeAll();
    });

    // ================= EDIT =================
    document.getElementById('editUserForm').addEventListener('submit', async (e) => {

        e.preventDefault();

        const res = await fetch('user_edit.php', {
            method: 'POST',
            body: new FormData(e.target)
        });

        const data = await res.json();

        if (!data.success) return alert(data.message);

        const u = data.user;

        const row = document.querySelector(`tr[data-id="${u.user_id}"]`);

        row.querySelector('.col-username').innerText = u.username;
        row.querySelector('.col-email').innerText = u.email;
        row.querySelector('.col-role').innerText = u.role;

        closeAll();
    });

    // ================= DELETE =================
    document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {

        const id = document.getElementById('delete_user_id').value;

        const res = await fetch(`user_delete.php?id=${id}`);
        const data = await res.json();

        if (!data.success) return alert(data.message);

        document.querySelector(`tr[data-id="${id}"]`).remove();

        closeAll();
    });

    // ================= GLOBAL FUNCTIONS =================
    window.openEdit = function (id, username, email, role) {

        document.getElementById('edit_user_id').value = id;
        document.getElementById('edit_username').value = username;
        document.getElementById('edit_email').value = email;
        document.getElementById('edit_role').value = role;

        openModal(editModal);
    }

    window.openDelete = function (id) {

        document.getElementById('delete_user_id').value = id;
        openModal(deleteModal);
    }

    function showToast(message) {

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerText = message;

    document.body.appendChild(toast);

    setTimeout(() => toast.classList.add('show'), 10);

    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 250);
    }, 2000);
}
});