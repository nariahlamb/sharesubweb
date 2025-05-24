// 在页面加载完成后执行
document.addEventListener('DOMContentLoaded', function() {
    // 为所有操作按钮添加确认提示
    const actionForms = document.querySelectorAll('form');
    
    actionForms.forEach(form => {
        form.addEventListener('submit', function(event) {
            // 获取按钮文本
            const button = form.querySelector('button');
            if (!button) return;
            
            const buttonText = button.textContent.trim();
            
            // 根据操作类型显示不同的确认信息
            let confirmMessage = '确定要执行此操作吗？';
            
            if (buttonText.includes('刷新')) {
                confirmMessage = '确定要刷新订阅吗？';
            } else if (buttonText.includes('测试')) {
                confirmMessage = '确定要测试所有节点吗？这可能需要一些时间。';
            } else if (buttonText.includes('生成')) {
                confirmMessage = '确定要生成订阅文件吗？';
            } else if (buttonText.includes('删除')) {
                confirmMessage = '确定要删除吗？此操作不可撤销！';
            }
            
            // 显示确认对话框
            if (!confirm(confirmMessage)) {
                event.preventDefault();
            }
        });
    });
    
    // 复制链接功能
    const copyLinks = document.querySelectorAll('.copy-link');
    
    copyLinks.forEach(link => {
        link.addEventListener('click', function(event) {
            event.preventDefault();
            
            const textToCopy = this.getAttribute('data-link');
            
            // 创建临时输入框
            const tempInput = document.createElement('input');
            tempInput.value = textToCopy;
            document.body.appendChild(tempInput);
            
            // 选择并复制
            tempInput.select();
            document.execCommand('copy');
            
            // 移除临时输入框
            document.body.removeChild(tempInput);
            
            // 显示复制成功提示
            alert('链接已复制到剪贴板！');
        });
    });
    
    // 标记活跃的导航链接
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('nav a');
    
    navLinks.forEach(link => {
        if (link.getAttribute('href') === currentPath) {
            link.classList.add('active');
        }
    });
}); 