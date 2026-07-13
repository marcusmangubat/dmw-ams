 /*  Logout  */
       export function logout(role) {
            Swal.fire({
                title: 'Are you sure?',
                text: `You will be logged out of the DMW ${role} Portal.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#0b1f4b',
                cancelButtonColor: '#dc2626',
                confirmButtonText: 'Yes, logout',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
                customClass: {
                    popup: 'rounded-3xl'
                }
            }).then(r => {
                if (r.isConfirmed) {
                    Swal.fire({
                        title: 'Logging out',
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        willOpen: () => Swal.showLoading()
                    });
                    setTimeout(() => window.location.href = '../logout.php', 800);
                }
            });
        }