function areYouSure() {
    Swal.fire({
        title: 'Tem certeza que deseja cancelar essa assinatura?',
        text: "Essa ação não poderá ser desfeita, mas uma nova assinatura pode ser realizada.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Sim, quero cancelar a assinatura',
        cancelButtonText: 'Cancelar',
    }).then((result) => {
        if (result.isConfirmed) {
            cancelSubscription();
        }
    }).catch((response) => {
        console.log("catch: " + response)
    })
}

function cancelSubscription() {
    var subs_id = jQuery('#sub_id').val();
    var order_id = jQuery('#order_id').val();

    var data = {
        action: "woocommerce_gerencianet_cancel_subscription",
        security: woocommerce_gerencianet_api.security,
        subs_id: subs_id,
        order_id: order_id
    };

    Swal.fire({
        title: 'Por favor, aguarde...',
        html:
            '<center><img src="' + woocommerce_gerencianet_api.loading_img + '" style="width:150px; margin:0;" ><br><p style="font-size: 20px;">Estamos processando sua solicitação.</p></center>',
        text: '',
        showConfirmButton: false,
    })

    jQuery.ajax({
        type: "POST",
        url: woocommerce_gerencianet_api.ajax_url,
        data: data,
        success: () => {
            Swal.fire({
                title: 'Assinatura Cancelada!',
                text: 'Essa assinatura foi cancelada com sucesso!',
                icon: 'success'
            }).then(() => {
                document.location.reload(true);
            })
        },
        error: function () {
            Swal.fire(
                'Ops!',
                'Houve um erro ao cancelar essa assinatura. Tente novamente mais tarde.',
                'error'
            )
        }
    });
}

function retryPixSubscriptionCharge(txid, minDate, maxDate) {
    // Usa HTML explícito para garantir compatibilidade do input de data
    Swal.fire({
        title: 'Escolha a data da retentativa',
        html: '<p style="margin-bottom:8px;">A data deve ser posterior a hoje e estar dentro de 7 dias da primeira rejeição.</p>' +
            '<input type="date" id="gn_retry_date" class="swal2-input" min="' + minDate + '" max="' + maxDate + '">',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Retentar cobrança',
        cancelButtonText: 'Cancelar',
        preConfirm: () => {
            const value = document.getElementById('gn_retry_date').value;
            if (!value) {
                Swal.showValidationMessage('Informe uma data para a retentativa.');
                return false;
            }
            if (value < minDate) {
                Swal.showValidationMessage('A data deve ser posterior à data atual.');
                return false;
            }
            if (value > maxDate) {
                Swal.showValidationMessage('A data deve estar em até 7 dias após a primeira rejeição.');
                return false;
            }
            return value;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // result.value traz o valor retornado por preConfirm
            sendPixSubscriptionRetry(txid, result.value);
        }
    }).catch((response) => {
        console.log("catch: " + response)
    })
}

function sendPixSubscriptionRetry(txid, retryDate) {
    var order_id = jQuery('#order_id').val();

    var data = {
        action: "woocommerce_gerencianet_retry_pix_subscription_charge",
        security: woocommerce_gerencianet_api.security,
        order_id: order_id,
        txid: txid,
        retry_date: retryDate
    };

    Swal.fire({
        title: 'Por favor, aguarde...',
        html:
            '<center><img src="' + woocommerce_gerencianet_api.loading_img + '" style="width:150px; margin:0;" ><br><p style="font-size: 20px;">Estamos processando sua retentativa.</p></center>',
        text: '',
        showConfirmButton: false,
    })

    jQuery.ajax({
        type: "POST",
        url: woocommerce_gerencianet_api.ajax_url,
        data: data,
        success: () => {
            Swal.fire({
                title: 'Retentativa solicitada!',
                text: 'A cobrança foi enviada para retentativa com sucesso.',
                icon: 'success'
            }).then(() => {
                document.location.reload(true);
            })
        },
        error: function (response) {
            var message = 'Houve um erro ao solicitar a retentativa. Tente novamente mais tarde.';

            if (response.responseJSON && response.responseJSON.data && response.responseJSON.data.message) {
                message = response.responseJSON.data.message;
            }

            Swal.fire(
                'Ops!',
                message,
                'error'
            )
        }
    });
}
